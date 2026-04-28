<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\AI\AIClientInterface;
use App\Entity\Department;
use App\Repository\JobDescriptionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Automated Skill-Gap Mapping: cross-references job descriptions with
 * current employee skill profiles to generate an organization-wide heat map.
 */
final class SkillGapMappingService
{
    public function __construct(
        private readonly AIClientInterface $ai,
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepo,
        private readonly JobDescriptionRepository $jdRepo,
        private readonly LoggerInterface $logger,
        private readonly string $model = 'gpt-4o',
    ) {}

    /**
     * Generate a complete skill gap report for the whole organization.
     *
     * @return array{
     *     heat_map: array<string, array{department: string, skill: string, current: float, target: float, gap: float, risk: string}>,
     *     critical_gaps: list<array>,
     *     recommendations: list<array{skill: string, priority: string, suggested_courses: list<string>}>,
     *     summary: string
     * }
     */
    public function generateOrganizationReport(int $organizationId): array
    {
        $departments = $this->em->getRepository(Department::class)->findBy(['organizationId' => $organizationId]);
        $heatMap     = [];
        $criticalGaps = [];

        foreach ($departments as $department) {
            $requiredSkills = $this->extractRequiredSkills($department);
            $currentSkills  = $this->aggregateCurrentSkills($department);
            $gaps           = $this->computeGaps($requiredSkills, $currentSkills, $department);

            foreach ($gaps as $gap) {
                $key = "{$department->getName()}::{$gap['skill']}";
                $heatMap[$key] = $gap;
                if ($gap['risk'] === 'critical') {
                    $criticalGaps[] = $gap;
                }
            }
        }

        $recommendations = $this->generateRecommendations($criticalGaps);
        $summary         = $this->generateExecutiveSummary($heatMap, $criticalGaps);

        return [
            'heat_map'         => $heatMap,
            'critical_gaps'    => $criticalGaps,
            'recommendations'  => $recommendations,
            'generated_at'     => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'summary'          => $summary,
        ];
    }

    /**
     * Use GPT to extract required skill proficiency levels from a JD.
     */
    private function extractRequiredSkills(Department $department): array
    {
        $jds = $this->jdRepo->findByDepartment($department);
        if (empty($jds)) {
            return [];
        }

        $jdText = implode("\n\n---\n\n", array_map(
            fn($jd) => "Role: {$jd->getTitle()}\n{$jd->getContent()}",
            $jds
        ));

        $content = $this->ai->chat(
            [
                ['role' => 'system', 'content' => 'You are an HR analytics AI. Extract required skills and proficiency levels from job descriptions. Respond ONLY with JSON.'],
                ['role' => 'user', 'content' => <<<PROMPT
                    Extract required skills from these job descriptions for the {$department->getName()} department.
                    For each skill, assign a required proficiency from 1 (basic) to 5 (expert).

                    Job Descriptions:
                    {$jdText}

                    Return JSON:
                    {
                        "skills": [
                            {"name": "<skill>", "category": "<technical|soft|domain>", "required_proficiency": <1-5>, "weight": <1-5>}
                        ]
                    }
                    PROMPT
                ],
            ],
            $this->model,
            ['max_tokens' => 1000, 'response_format' => ['type' => 'json_object']]
        );

        $result = json_decode($content, true);
        return $result['skills'] ?? [];
    }

    /**
     * Aggregate current skill levels across all team members.
     */
    private function aggregateCurrentSkills(Department $department): array
    {
        $users       = $this->userRepo->findByDepartment($department);
        $skillTotals = [];
        $skillCounts = [];

        foreach ($users as $user) {
            foreach ($user->getSkills() as $skillName => $proficiency) {
                $skillTotals[$skillName] = ($skillTotals[$skillName] ?? 0) + $proficiency;
                $skillCounts[$skillName] = ($skillCounts[$skillName] ?? 0) + 1;
            }
        }

        $averages = [];
        foreach ($skillTotals as $skill => $total) {
            $averages[$skill] = $total / $skillCounts[$skill];
        }

        return $averages;
    }

    /**
     * Compute per-skill gaps and assign risk level.
     */
    private function computeGaps(array $required, array $current, Department $dept): array
    {
        $gaps = [];
        foreach ($required as $skillDef) {
            $name    = $skillDef['name'];
            $target  = $skillDef['required_proficiency'];
            $actual  = $current[$name] ?? 0;
            $gapPct  = max(0, ($target - $actual) / $target * 100);

            $risk = match (true) {
                $gapPct >= 60 => 'critical',
                $gapPct >= 35 => 'high',
                $gapPct >= 15 => 'medium',
                default       => 'low',
            };

            $gaps[] = [
                'department' => $dept->getName(),
                'skill'      => $name,
                'category'   => $skillDef['category'],
                'current'    => round($actual, 2),
                'target'     => $target,
                'gap_percent' => round($gapPct, 1),
                'risk'       => $risk,
            ];
        }

        usort($gaps, fn($a, $b) => $b['gap_percent'] <=> $a['gap_percent']);
        return $gaps;
    }

    private function generateRecommendations(array $criticalGaps): array
    {
        if (empty($criticalGaps)) {
            return [];
        }

        $gapSummary = json_encode(array_slice($criticalGaps, 0, 10));

        $content = $this->ai->chat(
            [
                ['role' => 'system', 'content' => 'You are an L&D strategy advisor. Respond ONLY with JSON.'],
                ['role' => 'user', 'content' => "Generate training recommendations for these critical skill gaps:\n{$gapSummary}\n\nReturn: {\"recommendations\": [{\"skill\": \"...\", \"priority\": \"immediate|short_term|long_term\", \"approach\": \"...\", \"suggested_course_topics\": [...]}]}"],
            ],
            $this->model,
            ['max_tokens' => 800, 'response_format' => ['type' => 'json_object']]
        );

        $result = json_decode($content, true);
        return $result['recommendations'] ?? [];
    }

    private function generateExecutiveSummary(array $heatMap, array $criticalGaps): string
    {
        $totalGaps    = count($heatMap);
        $criticalCount = count($criticalGaps);
        $critSkills   = array_column($criticalGaps, 'skill');

        return "Organization-wide analysis identified {$totalGaps} skill gaps across all departments. "
             . "{$criticalCount} gaps are classified as critical risk. "
             . ($critSkills ? "Top critical skills: " . implode(', ', array_slice($critSkills, 0, 5)) . "." : '');
    }
}
