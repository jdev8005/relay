resource "aws_ssm_parameter" "pipeline_check" {
  name        = "/relay/pipeline-check"
  description = "Proves the OIDC pipeline can apply. Safe to delete."
  type        = "String"
  value       = "week-0"
}

output "pipeline_check_arn" {
  value = aws_ssm_parameter.pipeline_check.arn
}
