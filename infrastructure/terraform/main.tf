###############################################################################
# SimpleLMS AI Platform — AWS Infrastructure (Terraform)
# Architecture: ECS Fargate (app + workers) · RDS Aurora PostgreSQL ·
#               ElastiCache Redis · SQS · S3 · ALB · CloudFront · SES · ACM
###############################################################################

terraform {
  required_version = ">= 1.7"
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.40"
    }
  }
  backend "s3" {
    bucket         = "simpelms-terraform-state"
    key            = "production/terraform.tfstate"
    region         = "eu-west-1"
    encrypt        = true
    dynamodb_table = "simpelms-terraform-locks"
  }
}

provider "aws" {
  region = var.aws_region
  default_tags {
    tags = {
      Project     = "SimpeLMS-AI"
      Environment = var.environment
      ManagedBy   = "Terraform"
    }
  }
}

###############################################################################
# Variables
###############################################################################
variable "aws_region"        { default = "eu-west-1" }
variable "environment"       { default = "production" }
variable "app_name"          { default = "simpelms" }
variable "app_image"         { description = "ECR image URI for the PHP app" }
variable "worker_image"      { description = "ECR image URI for the Messenger worker" }
variable "db_password"       { sensitive = true }
variable "jwt_passphrase"    { sensitive = true }
variable "openai_api_key"    { sensitive = true }
variable "domain_name"       { default = "simpelms.example.com" }
variable "certificate_arn"   { description = "ACM certificate ARN for HTTPS" }

###############################################################################
# VPC
###############################################################################
module "vpc" {
  source  = "terraform-aws-modules/vpc/aws"
  version = "~> 5.0"

  name = "${var.app_name}-vpc"
  cidr = "10.0.0.0/16"

  azs              = ["${var.aws_region}a", "${var.aws_region}b", "${var.aws_region}c"]
  private_subnets  = ["10.0.1.0/24", "10.0.2.0/24", "10.0.3.0/24"]
  public_subnets   = ["10.0.101.0/24", "10.0.102.0/24", "10.0.103.0/24"]
  database_subnets = ["10.0.201.0/24", "10.0.202.0/24", "10.0.203.0/24"]

  enable_nat_gateway     = true
  single_nat_gateway     = false   # HA: one per AZ
  enable_dns_hostnames   = true
  enable_dns_support     = true

  create_database_subnet_group = true
}

###############################################################################
# Security Groups
###############################################################################
resource "aws_security_group" "alb" {
  name   = "${var.app_name}-alb-sg"
  vpc_id = module.vpc.vpc_id

  ingress {
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }
  ingress {
    from_port   = 80
    to_port     = 80
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }
  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }
}

resource "aws_security_group" "app" {
  name   = "${var.app_name}-app-sg"
  vpc_id = module.vpc.vpc_id

  ingress {
    from_port       = 9000
    to_port         = 9000
    protocol        = "tcp"
    security_groups = [aws_security_group.alb.id]
  }
  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }
}

resource "aws_security_group" "rds" {
  name   = "${var.app_name}-rds-sg"
  vpc_id = module.vpc.vpc_id

  ingress {
    from_port       = 5432
    to_port         = 5432
    protocol        = "tcp"
    security_groups = [aws_security_group.app.id]
  }
}

resource "aws_security_group" "redis" {
  name   = "${var.app_name}-redis-sg"
  vpc_id = module.vpc.vpc_id

  ingress {
    from_port       = 6379
    to_port         = 6379
    protocol        = "tcp"
    security_groups = [aws_security_group.app.id]
  }
}

###############################################################################
# RDS Aurora PostgreSQL (Multi-AZ)
###############################################################################
resource "aws_rds_cluster" "main" {
  cluster_identifier      = "${var.app_name}-aurora"
  engine                  = "aurora-postgresql"
  engine_version          = "16.2"
  database_name           = "simpelms"
  master_username         = "simpelms"
  master_password         = var.db_password
  db_subnet_group_name    = module.vpc.database_subnet_group_name
  vpc_security_group_ids  = [aws_security_group.rds.id]
  backup_retention_period = 14
  deletion_protection     = true
  storage_encrypted       = true
  skip_final_snapshot     = false
  final_snapshot_identifier = "${var.app_name}-final-snapshot"

  enabled_cloudwatch_logs_exports = ["postgresql"]
}

resource "aws_rds_cluster_instance" "instances" {
  count              = 2
  identifier         = "${var.app_name}-aurora-${count.index}"
  cluster_identifier = aws_rds_cluster.main.id
  instance_class     = "db.r7g.large"
  engine             = aws_rds_cluster.main.engine
}

###############################################################################
# ElastiCache Redis (Cluster mode)
###############################################################################
resource "aws_elasticache_replication_group" "redis" {
  replication_group_id = "${var.app_name}-redis"
  description          = "simpeLMS Redis — sessions, cache, rate limiting"
  node_type            = "cache.r7g.large"
  num_cache_clusters   = 2
  port                 = 6379
  subnet_group_name    = aws_elasticache_subnet_group.redis.name
  security_group_ids   = [aws_security_group.redis.id]
  at_rest_encryption_enabled = true
  transit_encryption_enabled = true
  automatic_failover_enabled = true
}

resource "aws_elasticache_subnet_group" "redis" {
  name       = "${var.app_name}-redis-subnet"
  subnet_ids = module.vpc.private_subnets
}

###############################################################################
# SQS Queues (Symfony Messenger transport)
###############################################################################
resource "aws_sqs_queue" "async" {
  name                       = "${var.app_name}-async.fifo"
  fifo_queue                 = true
  content_based_deduplication = true
  visibility_timeout_seconds = 300
  message_retention_seconds  = 86400
  redrive_policy = jsonencode({
    deadLetterTargetArn = aws_sqs_queue.failed.arn
    maxReceiveCount     = 3
  })
}

resource "aws_sqs_queue" "failed" {
  name       = "${var.app_name}-failed.fifo"
  fifo_queue = true
  message_retention_seconds = 1209600 # 14 days
}

###############################################################################
# S3 Buckets
###############################################################################
resource "aws_s3_bucket" "media" {
  bucket = "${var.app_name}-media-${var.environment}"
}

resource "aws_s3_bucket" "transcripts" {
  bucket = "${var.app_name}-transcripts-${var.environment}"
}

resource "aws_s3_bucket_versioning" "media" {
  bucket = aws_s3_bucket.media.id
  versioning_configuration { status = "Enabled" }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "media" {
  bucket = aws_s3_bucket.media.id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "aws:kms"
    }
  }
}

resource "aws_s3_bucket_public_access_block" "media" {
  bucket                  = aws_s3_bucket.media.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

###############################################################################
# ECR Repositories
###############################################################################
resource "aws_ecr_repository" "app" {
  name                 = "${var.app_name}/app"
  image_tag_mutability = "IMMUTABLE"
  image_scanning_configuration { scan_on_push = true }
}

resource "aws_ecr_repository" "worker" {
  name                 = "${var.app_name}/worker"
  image_tag_mutability = "IMMUTABLE"
  image_scanning_configuration { scan_on_push = true }
}

###############################################################################
# ECS Cluster
###############################################################################
resource "aws_ecs_cluster" "main" {
  name = "${var.app_name}-cluster"
  setting {
    name  = "containerInsights"
    value = "enabled"
  }
}

resource "aws_ecs_cluster_capacity_providers" "main" {
  cluster_name       = aws_ecs_cluster.main.name
  capacity_providers = ["FARGATE", "FARGATE_SPOT"]
  default_capacity_provider_strategy {
    capacity_provider = "FARGATE"
    weight            = 1
    base              = 1
  }
}

###############################################################################
# IAM Role for ECS Tasks
###############################################################################
resource "aws_iam_role" "ecs_task" {
  name = "${var.app_name}-ecs-task-role"
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Action    = "sts:AssumeRole"
      Effect    = "Allow"
      Principal = { Service = "ecs-tasks.amazonaws.com" }
    }]
  })
}

resource "aws_iam_role_policy" "ecs_task_policy" {
  name = "${var.app_name}-ecs-task-policy"
  role = aws_iam_role.ecs_task.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect   = "Allow"
        Action   = ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"]
        Resource = ["${aws_s3_bucket.media.arn}/*", "${aws_s3_bucket.transcripts.arn}/*"]
      },
      {
        Effect   = "Allow"
        Action   = ["sqs:SendMessage", "sqs:ReceiveMessage", "sqs:DeleteMessage", "sqs:GetQueueAttributes"]
        Resource = [aws_sqs_queue.async.arn, aws_sqs_queue.failed.arn]
      },
      {
        Effect   = "Allow"
        Action   = ["ses:SendEmail", "ses:SendRawEmail"]
        Resource = "*"
      },
      {
        Effect   = "Allow"
        Action   = ["secretsmanager:GetSecretValue"]
        Resource = "arn:aws:secretsmanager:${var.aws_region}:*:secret:${var.app_name}/*"
      },
      {
        Effect   = "Allow"
        Action   = ["transcribe:StartTranscriptionJob", "transcribe:GetTranscriptionJob"]
        Resource = "*"
      }
    ]
  })
}

###############################################################################
# Secrets Manager (app secrets)
###############################################################################
resource "aws_secretsmanager_secret" "app" {
  name = "${var.app_name}/production/app-secrets"
}

resource "aws_secretsmanager_secret_version" "app" {
  secret_id = aws_secretsmanager_secret.app.id
  secret_string = jsonencode({
    DB_PASSWORD      = var.db_password
    JWT_PASSPHRASE   = var.jwt_passphrase
    OPENAI_API_KEY   = var.openai_api_key
  })
}

###############################################################################
# Application Load Balancer
###############################################################################
resource "aws_lb" "main" {
  name               = "${var.app_name}-alb"
  internal           = false
  load_balancer_type = "application"
  security_groups    = [aws_security_group.alb.id]
  subnets            = module.vpc.public_subnets
  enable_deletion_protection = true

  access_logs {
    bucket  = aws_s3_bucket.media.id
    prefix  = "alb-logs"
    enabled = true
  }
}

resource "aws_lb_listener" "https" {
  load_balancer_arn = aws_lb.main.arn
  port              = 443
  protocol          = "HTTPS"
  ssl_policy        = "ELBSecurityPolicy-TLS13-1-2-2021-06"
  certificate_arn   = var.certificate_arn

  default_action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.app.arn
  }
}

resource "aws_lb_listener" "http_redirect" {
  load_balancer_arn = aws_lb.main.arn
  port              = 80
  protocol          = "HTTP"
  default_action {
    type = "redirect"
    redirect {
      port        = "443"
      protocol    = "HTTPS"
      status_code = "HTTP_301"
    }
  }
}

resource "aws_lb_target_group" "app" {
  name        = "${var.app_name}-tg"
  port        = 9000
  protocol    = "HTTP"
  vpc_id      = module.vpc.vpc_id
  target_type = "ip"

  health_check {
    path                = "/api/v1/health"
    healthy_threshold   = 2
    unhealthy_threshold = 3
    timeout             = 5
    interval            = 30
  }
}

###############################################################################
# CloudFront Distribution (media CDN)
###############################################################################
resource "aws_cloudfront_distribution" "media" {
  origin {
    domain_name              = aws_s3_bucket.media.bucket_regional_domain_name
    origin_id                = "S3-${aws_s3_bucket.media.id}"
    origin_access_control_id = aws_cloudfront_origin_access_control.media.id
  }

  enabled         = true
  is_ipv6_enabled = true
  comment         = "simpeLMS media CDN"

  default_cache_behavior {
    allowed_methods        = ["GET", "HEAD"]
    cached_methods         = ["GET", "HEAD"]
    target_origin_id       = "S3-${aws_s3_bucket.media.id}"
    viewer_protocol_policy = "redirect-to-https"
    cache_policy_id        = "658327ea-f89d-4fab-a63d-7e88639e58f6" # CachingOptimized
  }

  restrictions {
    geo_restriction { restriction_type = "none" }
  }

  viewer_certificate {
    cloudfront_default_certificate = true
  }
}

resource "aws_cloudfront_origin_access_control" "media" {
  name                              = "${var.app_name}-media-oac"
  origin_access_control_origin_type = "s3"
  signing_behavior                  = "always"
  signing_protocol                  = "sigv4"
}

###############################################################################
# EventBridge Scheduler — Nightly Retention Sweep
###############################################################################
resource "aws_scheduler_schedule" "retention_sweep" {
  name       = "${var.app_name}-retention-sweep"
  group_name = "default"

  flexible_time_window { mode = "OFF" }
  schedule_expression = "cron(0 2 * * ? *)"  # 02:00 UTC nightly

  target {
    arn      = aws_ecs_cluster.main.arn
    role_arn = aws_iam_role.scheduler.arn

    ecs_parameters {
      task_definition_arn = aws_ecs_task_definition.worker.arn
      launch_type         = "FARGATE"
      task_count          = 1

      network_configuration {
        assign_public_ip = false
        subnets          = module.vpc.private_subnets
        security_groups  = [aws_security_group.app.id]
      }
    }

    input = jsonencode({
      containerOverrides = [{
        name    = "worker"
        command = ["php", "bin/console", "app:retention:sweep"]
      }]
    })
  }
}

###############################################################################
# Outputs
###############################################################################
output "alb_dns"            { value = aws_lb.main.dns_name }
output "rds_endpoint"       { value = aws_rds_cluster.main.endpoint }
output "redis_endpoint"     { value = aws_elasticache_replication_group.redis.primary_endpoint_address }
output "sqs_async_url"      { value = aws_sqs_queue.async.url }
output "ecr_app_url"        { value = aws_ecr_repository.app.repository_url }
output "ecr_worker_url"     { value = aws_ecr_repository.worker.repository_url }
output "cloudfront_domain"  { value = aws_cloudfront_distribution.media.domain_name }
