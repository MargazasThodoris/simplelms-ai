<?php

declare(strict_types=1);

namespace App\Service\Storage;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Psr\Log\LoggerInterface;

/**
 * Thin wrapper around the AWS S3 SDK for uploading, downloading,
 * generating pre-signed URLs and deleting objects.
 */
final class S3Service
{
    public function __construct(
        private readonly S3Client $s3,
        private readonly LoggerInterface $logger,
        private readonly string $bucket,
        private readonly string $region,
    ) {}

    /**
     * Upload a local file to S3.
     */
    public function upload(string $key, string $localPath, string $contentType = 'application/octet-stream'): string
    {
        $this->s3->putObject([
            'Bucket'      => $this->bucket,
            'Key'         => $key,
            'SourceFile'  => $localPath,
            'ContentType' => $contentType,
            'ServerSideEncryption' => 'aws:kms',
        ]);

        $this->logger->info('S3 upload', ['key' => $key, 'bucket' => $this->bucket]);
        return $key;
    }

    /**
     * Upload raw content string to S3.
     */
    public function putContent(string $key, string $content, string $contentType = 'application/json'): void
    {
        $this->s3->putObject([
            'Bucket'      => $this->bucket,
            'Key'         => $key,
            'Body'        => $content,
            'ContentType' => $contentType,
            'ServerSideEncryption' => 'aws:kms',
        ]);
    }

    /**
     * Download an S3 object to a local file.
     */
    public function download(string $key, string $localPath): void
    {
        $this->s3->getObject([
            'Bucket' => $this->bucket,
            'Key'    => $key,
            'SaveAs' => $localPath,
        ]);
    }

    /**
     * Get raw object content as string.
     */
    public function getContent(string $key): string
    {
        $result = $this->s3->getObject([
            'Bucket' => $this->bucket,
            'Key'    => $key,
        ]);
        return (string) $result['Body'];
    }

    /**
     * Generate a pre-signed URL for temporary secure access to a private object.
     */
    public function getPresignedUrl(string $key, int $expiresInSeconds = 3600): string
    {
        $cmd = $this->s3->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key'    => $key,
        ]);

        $request = $this->s3->createPresignedRequest($cmd, "+{$expiresInSeconds} seconds");
        return (string) $request->getUri();
    }

    /**
     * Generate a pre-signed POST URL for direct browser-to-S3 upload.
     * Used for large video/asset uploads from the frontend.
     */
    public function getPresignedUploadUrl(
        string $key,
        string $contentType,
        int $maxBytes = 52428800,   // 50 MB
        int $expiresInSeconds = 900,
    ): array {
        return $this->s3->createPresignedRequest(
            $this->s3->getCommand('PutObject', [
                'Bucket'       => $this->bucket,
                'Key'          => $key,
                'ContentType'  => $contentType,
                'ContentLength' => $maxBytes,
            ]),
            "+{$expiresInSeconds} seconds"
        )->getUri()->__toString() ? [
            'url'     => $this->getPresignedUrl($key, $expiresInSeconds),
            'key'     => $key,
            'expires' => $expiresInSeconds,
        ] : [];
    }

    /**
     * Check if an object exists.
     */
    public function exists(string $key): bool
    {
        try {
            $this->s3->headObject(['Bucket' => $this->bucket, 'Key' => $key]);
            return true;
        } catch (S3Exception) {
            return false;
        }
    }

    /**
     * Delete an object.
     */
    public function delete(string $key): void
    {
        $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
        $this->logger->info('S3 delete', ['key' => $key]);
    }

    /**
     * Copy an object within the same bucket (e.g. from temp prefix to permanent).
     */
    public function copy(string $sourceKey, string $destKey): void
    {
        $this->s3->copyObject([
            'Bucket'     => $this->bucket,
            'CopySource' => "{$this->bucket}/{$sourceKey}",
            'Key'        => $destKey,
            'ServerSideEncryption' => 'aws:kms',
        ]);
    }

    public function getBucket(): string { return $this->bucket; }
    public function getRegion(): string { return $this->region; }
}
