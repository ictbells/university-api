resource "aws_iam_role" "ec2" {
  name = "${var.environment}-bells-sis-ec2"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect = "Allow"
      Principal = {
        Service = "ec2.amazonaws.com"
      }
      Action = "sts:AssumeRole"
    }]
  })
}

resource "aws_iam_role_policy_attachment" "ssm_core" {
  role       = aws_iam_role.ec2.name
  policy_arn = "arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore"
}

resource "aws_iam_role_policy" "ec2_inline" {
  name = "${var.environment}-bells-sis-ec2"
  role = aws_iam_role.ec2.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = concat(
      [
        {
          Sid    = "ReadBootstrapAndDeployArtifacts"
          Effect = "Allow"
          Action = [
            "s3:GetObject",
            "s3:GetObjectVersion"
          ]
          Resource = [
            "${aws_s3_bucket.bootstrap.arn}/${local.bootstrap_s3_key}",
            "${aws_s3_bucket.bootstrap.arn}/deploys/*"
          ]
        },
        {
          Sid    = "ReadAppSecrets"
          Effect = "Allow"
          Action = [
            "secretsmanager:GetSecretValue",
            "secretsmanager:DescribeSecret"
          ]
          Resource = distinct(compact([
            aws_secretsmanager_secret.app.arn,
            "${aws_secretsmanager_secret.app.arn}-*",
            aws_secretsmanager_secret.app_key.arn,
            "${aws_secretsmanager_secret.app_key.arn}-*",
            length(data.aws_secretsmanager_secret.rds_master) > 0 ? data.aws_secretsmanager_secret.rds_master[0].arn : "",
            length(data.aws_secretsmanager_secret.rds_master) > 0 ? "${data.aws_secretsmanager_secret.rds_master[0].arn}-*" : "",
          ]))
        }
      ]
    )
  })
}

resource "aws_iam_instance_profile" "ec2" {
  name = "${var.environment}-bells-sis-ec2"
  role = aws_iam_role.ec2.name
}
