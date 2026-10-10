# ADR-0013: NAT Gateway over the Alternatives

- **Status:** Accepted
- **Date:** 2026-09-21

## Context

The private subnets need outbound internet access — ECR pulls, CloudWatch Logs,
Secrets Manager, and partner API calls — while nothing in them should be
reachable inbound.

The project operates under a hard budget of $25/month.

## Decision

A single NAT Gateway in one availability zone. This was the best option
architecturally that also stayed within budget.

The rate is $0.045/hour plus $0.045/GB processed. Running continuously that
would be roughly $32/month, but the planned nightly destroy-and-rebuild cycle
brings it to approximately $13.50/month.

### Alternatives considered

| Approach | Monthly cost | Why not |
|---|---|---|
| NAT instance (t4g.nano) | ~$3 | Requires operating a separate EC2 instance |
| Public subnets, no NAT | $0 | The only security boundary becomes the security group |
| VPC interface endpoints | ~$29 | Costs more than the NAT it would replace |

**VPC endpoints are the standard advice for avoiding NAT costs, and here they
cost more than the thing they replace.** Four endpoints are needed — one per
service consumed: ECR API, ECR Docker, CloudWatch Logs, and Secrets Manager —
at $7.20/month each.

Endpoint cost scales with the number of services consumed rather than with
runtime, so unlike the NAT Gateway it does not shrink under the destroy cycle.
Four endpoints stay at ~$29/month whether the network runs 10 hours a day or 24.
The conventional recommendation assumes a continuously-running NAT, and it does
not survive that assumption changing.

## Consequences

### The destroy cycle is what makes this viable

A continuously-running NAT Gateway would cost ~$32/month against a $25 budget.
Without the nightly cycle this decision would have gone to the NAT instance or
to no NAT at all.

The cost effectiveness came out of two separate decisions. The nightly
destroy-and-rebuild was adopted to avoid paying for idle infrastructure and to
hold the budget. Once it existed, the NAT Gateway became affordable — and it was
the more attractive option on architectural grounds.

Using a NAT Gateway disallows inbound traffic by not making the capability
available to the subnet at all, rather than relying on security group policy
alone in public subnets, or on operating a separate EC2 instance.

**This couples the two decisions.** If the nightly destroy cycle is ever
removed, this networking choice has to be revisited immediately.

### Accepted risks

- **Single point of failure.** One NAT Gateway in one AZ. If that AZ fails,
  both private subnets lose outbound connectivity. A second NAT would double the
  cost to buy availability for a single-task portfolio project.

- **The public source address changes on every rebuild.** The Elastic IP is
  released on destroy — observed as 3.141.239.55 on the first build and
  18.227.45.3 on the second. If a partner ever requires a fixed source address
  to allowlist, the EIP must move to the `bootstrap/` configuration so it
  survives the cycle, at roughly $3.60/month for an EIP that sits unattached
  while the network is down.
