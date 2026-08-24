resource "aws_security_group" "ec2" {
  name_prefix = "${var.environment}-bells-sis-ec2-"
  description = "Bells SIS API HTTP/HTTPS; MySQL via existing RDS SG rule"
  vpc_id      = local.vpc_id

  ingress {
    description = "HTTP (ACME + redirect)"
    from_port   = 80
    to_port     = 80
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  ingress {
    description = "HTTPS"
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  dynamic "ingress" {
    for_each = var.ssh_ingress_cidr != "" ? [var.ssh_ingress_cidr] : []
    content {
      description = "SSH"
      from_port   = 22
      to_port     = 22
      protocol    = "tcp"
      cidr_blocks = [ingress.value]
    }
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  lifecycle {
    create_before_destroy = true
  }

  tags = {
    Name = "${var.environment}-bells-sis-ec2"
  }
}

# Open existing RDS security group(s) to this EC2 only. Does not replace BankEase rules.
resource "aws_vpc_security_group_ingress_rule" "rds_from_ec2" {
  for_each = toset(data.aws_db_instance.existing.vpc_security_groups)

  security_group_id            = each.value
  referenced_security_group_id = aws_security_group.ec2.id
  from_port                    = 3306
  to_port                      = 3306
  ip_protocol                  = "tcp"
  description                  = "MySQL from ${var.environment} bells-sis EC2"
}
