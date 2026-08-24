resource "aws_secretsmanager_secret" "app" {
  name                    = "${var.environment}/bells-sis/rds/app"
  recovery_window_in_days = 0
}

resource "aws_secretsmanager_secret_version" "app" {
  secret_id = aws_secretsmanager_secret.app.id
  secret_string = jsonencode({
    username = var.db_username
    password = local.db_password_effective
    host     = data.aws_db_instance.existing.address
    port     = data.aws_db_instance.existing.port
    dbname   = var.db_name
    engine   = "mysql"
  })
}

resource "aws_secretsmanager_secret" "app_key" {
  name                    = "${var.environment}/bells-sis/app-key"
  recovery_window_in_days = 0
}

resource "aws_secretsmanager_secret_version" "app_key" {
  secret_id     = aws_secretsmanager_secret.app_key.id
  secret_string = var.app_key != "" ? var.app_key : "base64:${base64encode(random_password.app_key_bytes.result)}"
}
