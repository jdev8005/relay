output "state_bucket" {
  value       = aws_s3_bucket.state.id
  description = "S3 bucket holding Terraform state for both configurations."
}

output "state_kms_key_arn" {
  value       = aws_kms_key.state.arn
  description = "CMK encrypting Terraform state. Needed for the main stack's backend block."
}

output "gha_plan_role_arn" {
  value       = aws_iam_role.gha_plan.arn
  description = "Read-only role assumed by CI on any branch."
}

output "gha_apply_role_arn" {
  value       = aws_iam_role.gha_apply.arn
  description = "Write role assumed by CI only from the production environment."
}
