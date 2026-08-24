resource "aws_instance" "api" {
  ami                    = data.aws_ami.ubuntu.id
  instance_type          = var.instance_type
  subnet_id              = local.ec2_subnet_id
  vpc_security_group_ids = [aws_security_group.ec2.id]
  iam_instance_profile   = aws_iam_instance_profile.ec2.name
  key_name               = var.key_name != "" ? var.key_name : null

  associate_public_ip_address = true
  user_data_replace_on_change = true

  user_data = templatefile("${path.module}/templates/user_data_launcher.sh.tftpl", {
    region               = var.aws_region
    bootstrap_s3_bucket  = aws_s3_bucket.bootstrap.id
    bootstrap_s3_key     = local.bootstrap_s3_key
    bootstrap_script_md5 = local.bootstrap_script_md5
  })

  depends_on = [
    aws_s3_object.ec2_bootstrap,
    aws_secretsmanager_secret_version.app,
    aws_secretsmanager_secret_version.app_key,
    aws_vpc_security_group_ingress_rule.rds_from_ec2,
  ]

  metadata_options {
    http_tokens = "required"
  }

  root_block_device {
    volume_size           = var.root_volume_gb
    volume_type           = "gp3"
    encrypted             = true
    delete_on_termination = true
  }

  tags = {
    Name = "${var.environment}-bells-sis-api"
  }

  lifecycle {
    precondition {
      condition     = local.ec2_subnet_id != null
      error_message = "No public subnet in VPC ${local.vpc_id} supports instance_type=${var.instance_type}."
    }
  }
}

resource "aws_eip" "api" {
  domain = "vpc"
  tags = {
    Name = "${var.environment}-bells-sis-api"
  }
}

resource "aws_eip_association" "api" {
  instance_id   = aws_instance.api.id
  allocation_id = aws_eip.api.id
}
