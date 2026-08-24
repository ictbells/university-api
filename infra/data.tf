data "aws_caller_identity" "current" {}

data "aws_region" "current" {}

data "aws_route53_zone" "public" {
  name         = "${var.hosted_zone_name}."
  private_zone = false
}

data "aws_db_instance" "existing" {
  db_instance_identifier = var.rds_instance_identifier
}

# Resolve VPC from the first RDS security group.
data "aws_security_group" "rds_primary" {
  id = data.aws_db_instance.existing.vpc_security_groups[0]
}

data "aws_subnets" "vpc_public" {
  filter {
    name   = "vpc-id"
    values = [local.vpc_id]
  }

  filter {
    name   = "map-public-ip-on-launch"
    values = ["true"]
  }
}

data "aws_subnets" "vpc_all" {
  filter {
    name   = "vpc-id"
    values = [local.vpc_id]
  }
}

data "aws_subnet" "public_detail" {
  for_each = toset(local.public_subnet_ids)
  id       = each.value
}

data "aws_ec2_instance_type_offerings" "instance_azs" {
  filter {
    name   = "instance-type"
    values = [var.instance_type]
  }

  location_type = "availability-zone"
}

data "aws_ami" "ubuntu" {
  most_recent = true
  owners      = ["099720109477"] # Canonical

  filter {
    name   = "name"
    values = ["ubuntu/images/hvm-ssd*/ubuntu-noble-24.04-amd64-server-*"]
  }

  filter {
    name   = "virtualization-type"
    values = ["hvm"]
  }

  filter {
    name   = "root-device-type"
    values = ["ebs"]
  }
}

data "aws_cloudfront_cache_policy" "caching_optimized" {
  name = "Managed-CachingOptimized"
}

data "aws_cloudfront_cache_policy" "caching_disabled" {
  name = "Managed-CachingDisabled"
}

data "aws_secretsmanager_secret" "rds_master" {
  count = var.rds_master_secret_name != "" ? 1 : 0
  name  = var.rds_master_secret_name
}
