###############################################################################
# ECS Task Definitions — App (nginx+php-fpm) & Worker (Messenger consumer)
###############################################################################

locals {
  common_environment = [
    { name = "APP_ENV",              value = "prod" },
    { name = "AWS_REGION",           value = var.aws_region },
    { name = "AWS_S3_BUCKET",        value = aws_s3_bucket.media.id },
    { name = "AWS_S3_BUCKET_TRANSCRIPTS", value = aws_s3_bucket.transcripts.id },
    { name = "MESSENGER_TRANSPORT_DSN",   value = "sqs://${aws_sqs_queue.async.url}" },
  ]

  common_secrets = [
    { name = "DB_PASSWORD",    valueFrom = "${aws_secretsmanager_secret.app.arn}:DB_PASSWORD::" },
    { name = "JWT_PASSPHRASE", valueFrom = "${aws_secretsmanager_secret.app.arn}:JWT_PASSPHRASE::" },
    { name = "OPENAI_API_KEY", valueFrom = "${aws_secretsmanager_secret.app.arn}:OPENAI_API_KEY::" },
  ]
}

###############################################################################
# App Task (php-fpm + nginx sidecar)
###############################################################################
resource "aws_ecs_task_definition" "app" {
  family                   = "${var.app_name}-app"
  network_mode             = "awsvpc"
  requires_compatibilities = ["FARGATE"]
  cpu                      = "1024"   # 1 vCPU
  memory                   = "2048"   # 2 GB
  execution_role_arn       = aws_iam_role.ecs_execution.arn
  task_role_arn            = aws_iam_role.ecs_task.arn

  container_definitions = jsonencode([
    {
      name      = "nginx"
      image     = "nginx:1.25-alpine"
      essential = true
      portMappings = [{ containerPort = 80, hostPort = 80 }]
      mountPoints = [{
        containerPath = "/var/www/html"
        sourceVolume  = "app-code"
        readOnly      = true
      }]
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = "/ecs/${var.app_name}/nginx"
          "awslogs-region"        = var.aws_region
          "awslogs-stream-prefix" = "ecs"
        }
      }
      dependsOn = [{ containerName = "php-fpm", condition = "START" }]
    },
    {
      name      = "php-fpm"
      image     = var.app_image
      essential = true
      portMappings = [{ containerPort = 9000, hostPort = 9000 }]
      environment = concat(local.common_environment, [
        { name = "DATABASE_URL", value = "postgresql://talentlms:$(DB_PASSWORD)@${aws_rds_cluster.main.endpoint}:5432/talentlms" },
        { name = "REDIS_URL",    value = "redis://${aws_elasticache_replication_group.redis.primary_endpoint_address}:6379" },
      ])
      secrets = local.common_secrets
      mountPoints = [{
        containerPath = "/var/www/html"
        sourceVolume  = "app-code"
        readOnly      = false
      }]
      healthCheck = {
        command     = ["CMD-SHELL", "php-fpm -t || exit 1"]
        interval    = 30
        timeout     = 5
        retries     = 3
        startPeriod = 60
      }
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = "/ecs/${var.app_name}/php-fpm"
          "awslogs-region"        = var.aws_region
          "awslogs-stream-prefix" = "ecs"
        }
      }
    }
  ])

  volume {
    name = "app-code"
  }
}

###############################################################################
# Worker Task (Symfony Messenger consumer — processes SQS jobs)
###############################################################################
resource "aws_ecs_task_definition" "worker" {
  family                   = "${var.app_name}-worker"
  network_mode             = "awsvpc"
  requires_compatibilities = ["FARGATE"]
  cpu                      = "512"
  memory                   = "1024"
  execution_role_arn       = aws_iam_role.ecs_execution.arn
  task_role_arn            = aws_iam_role.ecs_task.arn

  container_definitions = jsonencode([
    {
      name      = "worker"
      image     = var.worker_image
      essential = true
      command   = ["php", "bin/console", "messenger:consume", "async", "--time-limit=3600", "--memory-limit=512M", "-vv"]
      environment = concat(local.common_environment, [
        { name = "DATABASE_URL", value = "postgresql://talentlms:$(DB_PASSWORD)@${aws_rds_cluster.main.endpoint}:5432/talentlms" },
        { name = "REDIS_URL",    value = "redis://${aws_elasticache_replication_group.redis.primary_endpoint_address}:6379" },
      ])
      secrets = local.common_secrets
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = "/ecs/${var.app_name}/worker"
          "awslogs-region"        = var.aws_region
          "awslogs-stream-prefix" = "ecs"
        }
      }
    }
  ])
}

###############################################################################
# ECS Services
###############################################################################
resource "aws_ecs_service" "app" {
  name            = "${var.app_name}-app"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.app.arn
  desired_count   = 2
  launch_type     = "FARGATE"

  network_configuration {
    subnets          = module.vpc.private_subnets
    security_groups  = [aws_security_group.app.id]
    assign_public_ip = false
  }

  load_balancer {
    target_group_arn = aws_lb_target_group.app.arn
    container_name   = "nginx"
    container_port   = 80
  }

  deployment_circuit_breaker {
    enable   = true
    rollback = true
  }

  deployment_controller { type = "ECS" }

  lifecycle {
    ignore_changes = [desired_count]  # managed by auto-scaling
  }
}

resource "aws_ecs_service" "worker" {
  name            = "${var.app_name}-worker"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.worker.arn
  desired_count   = 2
  launch_type     = "FARGATE"

  network_configuration {
    subnets          = module.vpc.private_subnets
    security_groups  = [aws_security_group.app.id]
    assign_public_ip = false
  }

  deployment_circuit_breaker {
    enable   = true
    rollback = true
  }

  lifecycle {
    ignore_changes = [desired_count]
  }
}

###############################################################################
# Auto-Scaling — App Service
###############################################################################
resource "aws_appautoscaling_target" "app" {
  service_namespace  = "ecs"
  resource_id        = "service/${aws_ecs_cluster.main.name}/${aws_ecs_service.app.name}"
  scalable_dimension = "ecs:service:DesiredCount"
  min_capacity       = 2
  max_capacity       = 20
}

resource "aws_appautoscaling_policy" "app_cpu" {
  name               = "${var.app_name}-app-cpu-scaling"
  policy_type        = "TargetTrackingScaling"
  resource_id        = aws_appautoscaling_target.app.resource_id
  scalable_dimension = aws_appautoscaling_target.app.scalable_dimension
  service_namespace  = aws_appautoscaling_target.app.service_namespace

  target_tracking_scaling_policy_configuration {
    target_value = 60.0
    predefined_metric_specification {
      predefined_metric_type = "ECSServiceAverageCPUUtilization"
    }
    scale_in_cooldown  = 300
    scale_out_cooldown = 60
  }
}

###############################################################################
# IAM Roles
###############################################################################
resource "aws_iam_role" "ecs_execution" {
  name = "${var.app_name}-ecs-execution-role"
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Action    = "sts:AssumeRole"
      Effect    = "Allow"
      Principal = { Service = "ecs-tasks.amazonaws.com" }
    }]
  })
}

resource "aws_iam_role_policy_attachment" "ecs_execution_policy" {
  role       = aws_iam_role.ecs_execution.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy"
}

resource "aws_iam_role_policy" "ecs_execution_secrets" {
  name = "${var.app_name}-execution-secrets"
  role = aws_iam_role.ecs_execution.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect   = "Allow"
      Action   = ["secretsmanager:GetSecretValue", "ssm:GetParameters"]
      Resource = "*"
    }]
  })
}

resource "aws_iam_role" "scheduler" {
  name = "${var.app_name}-scheduler-role"
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Action    = "sts:AssumeRole"
      Effect    = "Allow"
      Principal = { Service = "scheduler.amazonaws.com" }
    }]
  })
}

resource "aws_iam_role_policy" "scheduler" {
  name = "${var.app_name}-scheduler-policy"
  role = aws_iam_role.scheduler.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect   = "Allow"
      Action   = ["ecs:RunTask", "iam:PassRole"]
      Resource = "*"
    }]
  })
}

###############################################################################
# CloudWatch Log Groups
###############################################################################
resource "aws_cloudwatch_log_group" "nginx"   { name = "/ecs/${var.app_name}/nginx";   retention_in_days = 30 }
resource "aws_cloudwatch_log_group" "php_fpm" { name = "/ecs/${var.app_name}/php-fpm"; retention_in_days = 30 }
resource "aws_cloudwatch_log_group" "worker"  { name = "/ecs/${var.app_name}/worker";  retention_in_days = 30 }

###############################################################################
# CloudWatch Alarms
###############################################################################
resource "aws_cloudwatch_metric_alarm" "high_cpu" {
  alarm_name          = "${var.app_name}-high-cpu"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 2
  metric_name         = "CPUUtilization"
  namespace           = "AWS/ECS"
  period              = 60
  statistic           = "Average"
  threshold           = 80
  alarm_description   = "ECS service CPU above 80%"
  dimensions = {
    ClusterName = aws_ecs_cluster.main.name
    ServiceName = aws_ecs_service.app.name
  }
}

resource "aws_cloudwatch_metric_alarm" "sqs_depth" {
  alarm_name          = "${var.app_name}-sqs-depth"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 3
  metric_name         = "ApproximateNumberOfMessagesVisible"
  namespace           = "AWS/SQS"
  period              = 60
  statistic           = "Average"
  threshold           = 100
  alarm_description   = "SQS queue depth above 100 — consider scaling workers"
  dimensions          = { QueueName = aws_sqs_queue.async.name }
}
