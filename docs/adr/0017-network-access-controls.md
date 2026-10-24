# ADR-0017: Network Access Controls

- **Status:** Accepted
- **Date:** 2026-10-16

## Context

ADR-0013 established the network layout: a `10.0.0.0/16` VPC with two public
subnets (`10.0.0.0/24`, `10.0.1.0/24`) and two private subnets (`10.0.10.0/24`,
`10.0.11.0/24`) across two availability zones, with a single NAT Gateway for
private-subnet egress.

The layout says where things live. It does not say what may talk to what. That
is decided by three settings:

- Whether resources launched into public subnets receive a public IP
  automatically.
- What the load balancer's security group allows in and out.
- What the application's security group allows in and out.

The intended traffic path is narrow: the internet reaches the ALB on 443, the
ALB reaches the application on 8080, and the application reaches outward through
the NAT Gateway. Nothing else should be possible.

Trivy's configuration scan in pre-commit and CI flagged the first draft of
`network.tf` and `security-group.tf` for an auto-assigned public IP setting and
unrestricted egress on both security groups.

## Decision

**1. Public subnets do not auto-assign public IPs.** `map_public_ip_on_launch`
is removed from `aws_subnet.public`, leaving the AWS default of `false`.

The only things in the public subnets are the ALB and the NAT Gateway, and
neither depends on this setting. The NAT Gateway uses its own Elastic IP, and
the ALB's public addresses are managed by AWS. The setting would only ever
affect something placed there by mistake, such as a Fargate task with the wrong
subnet IDs, and it would give that mistake a public address.

**2. The ALB security group is limited to its role in the path.**

| Direction | Port     | Peer                                 |
| --------- | -------- | ------------------------------------ |
| Ingress   | tcp/443  | `0.0.0.0/0`, public by design         |
| Egress    | tcp/8080 | VPC CIDR (`aws_vpc.main.cidr_block`) |

**3. The application security group accepts traffic only from the ALB.**

| Direction | Port     | Peer                                   |
| --------- | -------- | -------------------------------------- |
| Ingress   | tcp/8080 | ALB security group (SG reference)      |
| Egress    | all      | `0.0.0.0/0`, accepted risk (see below) |

The ingress rule references the ALB's security group rather than a CIDR, so it
admits traffic from the load balancer's network interfaces and nothing else in
the VPC.

**4. The accepted finding is suppressed inline, on the one resource.** The
application security group carries `#trivy:ignore:AWS-0104` directly above
the resource, with a comment stating why, rather than an entry in
`.trivyignore.yaml` (see alternatives for why a file-scoped entry is too
broad here).

### Alternatives considered

- **Keep `map_public_ip_on_launch = true`.** Common in tutorials, and harmless
  while only the ALB and NAT live in public subnets. Rejected: it adds nothing
  the current resources need and turns a subnet mistake into an exposure.
- **ALB egress by security group reference instead of VPC CIDR.** Tighter: the
  ALB could reach only the application's interfaces, not every address in the
  VPC. Rejected for now because the two inline rule sets reference each other.
  The ALB group's egress names the app group, and the app group's ingress names
  the ALB group, which Terraform reports as a dependency cycle. Fixing it means
  moving rules out of the `aws_security_group` blocks into standalone
  `aws_vpc_security_group_ingress_rule` and `aws_vpc_security_group_egress_rule`
  resources. That is the provider's recommended style and the eventual target,
  but it is a structural change to every rule for a gain that is small while
  nothing else listens on 8080 in the VPC.
- **Restrict application egress to known destinations.** Rejected because the
  destinations can't be enumerated. With no VPC endpoints (ADR-0013), ECR,
  CloudWatch Logs, and any partner API are reached at public AWS or third-party
  IP addresses that change without notice. Security groups match on IP, port,
  and protocol, not hostname.
- **VPC interface endpoints for AWS services, with egress limited to them.**
  This would make restricted egress practical. Rejected on cost in ADR-0013:
  about $29/month against a $25 budget.
- **An entry in `.trivyignore.yaml`.** The repository already uses this file
  for two suppressions (AWS-0089 on the state bucket, DS-0026 on the
  Dockerfile), each scoped by path, with a statement and an expiry date.
  Rejected for this finding because `paths:` scopes by file, not by resource.
  `security-group.tf` defines both security groups, so a file-level entry
  would also suppress AWS-0104 on the ALB group, whose egress was deliberately
  narrowed to pass that check. An inline ignore binds to one block, so the ALB
  group stays under scan.

  The resulting rule: use `.trivyignore.yaml` when the finding is the only
  instance in its file, and inline when the file holds both compliant and
  accepted instances of the same check.

## Implementation notes

**Removing one argument is not removing one block.** Deleting
`map_public_ip_on_launch` accidentally removed the whole `aws_subnet.public`
resource. The next plan failed with `Reference to undeclared resource` from the
route table associations and the NAT Gateway, which both index into
`aws_subnet.public`. When a reference error appears after an edit, check
whether the edit removed more than intended before debugging the references.

**A stale `tfplan` produces phantom scan findings.** After the fixes, Trivy kept
reporting the original problems, labelled `(terraformplan-snapshot)`. A plan
file saved before the fixes was still in the directory, and Trivy scans plan
files as well as source. The source was correct; the leftover artifact was not.
Deleting `tfplan` after each apply prevents it, which is why the apply command
in `docs/commands.md` is `terraform apply tfplan && rm tfplan`.

**Inline ignores must sit directly above the resource they apply to.** Trivy
binds `#trivy:ignore` to the block that immediately follows it. Explanatory
comments go above the ignore line, not between it and the resource.

**The SG reference creates an implicit apply order.** Because the application
group's ingress names the ALB group, Terraform creates the ALB group first
without any `depends_on`. This is the same mechanism that causes the cycle if
both groups reference each other.

## Consequences

**Positive**

- The application tier has no inbound path except through the load balancer,
  even from inside the VPC.
- A resource placed in a public subnet by mistake does not get a public address.
- The accepted finding is visible in the code that causes it, with its reason
  next to it, and shows up in review if it changes. The ALB group in the same
  file remains fully scanned.
- Trivy runs clean, so any new finding is a real signal rather than noise.

**Negative**

- The ALB can reach any address in the VPC on 8080, not just the application.
- Application tasks can open connections to any address on the internet.
- Two styles of rule definition will coexist once standalone rule resources are
  introduced, until the existing inline rules are migrated.

**Accepted risks**

- **Unrestricted application egress (AWS-0104).** A compromised task could send
  data anywhere. It is tolerated because the destinations it legitimately needs
  can't be expressed as IP ranges without VPC endpoints. Revisit if the budget
  allows endpoints, or if an egress-filtering layer (such as AWS Network
  Firewall) becomes justified.
- **ALB egress to the whole VPC CIDR on 8080.** Tolerated while the application
  is the only listener on that port. Revisit when a second service is added to
  the VPC, or when rules are migrated to standalone resources, whichever comes
  first.
- **Public ingress on 443.** This is the service's purpose. Protection against
  abuse belongs at a different layer (rate limiting, AWS WAF, and signature
  verification in the application per ADR-0008), not in the security group.

## Related

- ADR-0008: Accept-and-queue webhook ingestion (signature verification at the
  application layer)
- ADR-0013: NAT Gateway over the alternatives (why there are no VPC endpoints)
- ADR-0014: Production image design for Fargate (the container listening on
  8080)
