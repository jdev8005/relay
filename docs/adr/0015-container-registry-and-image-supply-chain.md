# ADR-0015: Container registry and image supply chain

- **Status:** Accepted
- **Date:** 2026-10-11

## Context

- The images that live in ECR are immutable deployment artifacts which are built once by CI, because with a rebuild even if the code hasn't changed underlying dependencies or system packages could have updated.
- Given this the artifacts (images, because they are built once and deployed and are traceable back to its source commit) need to be retained independently of the infrastructure that will run them.

## Decision

- ECR config lives inside the bootstrap folder with the prevent_destroy option defined in ecr.tf. because this is something that can't be regenerated after a destroy.
- The repository uses IMMUTABLE tag mutability, and images are tagged sha-<commit>, so every image traces to exactly one commit and a tag can never be re-pointed.
- An ECR lifecycle policy keeps the 10 most recent sha- tagged images and expires untagged images after one day. The purpose isn’t saving money out of necessity but placing a deliberate constraint: a flat storage cost rather than one growing without limit, mirroring the habits needed in a production environment.
- Reuse the same CMK, same project and trust boundary. Safe because each role only has access to its specified resources.
- Basic scan-on-push, with build attestations disabled (provenance: false, sbom: false) so BuildKit produces a plain image that ECR can scan rather than an index.
- [composer audit --locked] in CI, blocking. Basic scanning only covers OS packages, and this covers the PHP side.

### Alternatives considered

- Using mutable tags plus :latest but this allows the scenario where deploy succeeds but production runs something different.
- Separate KMS key for ECR, better separation but it's the same trust boundary for a small single person project and extra cost.
- Non-blocking or nightly audits considered because it avoids unrelated PR's causing others to fail. Rejected because the block forces a fix
- Amazon Inspector, beneficial for continuous re-scanning but is not documented to support image indexes so this wouldn't be expected to fix the attestations issue, also cost per image.
- Trivy in CI before push, this would eliminate the need for an ECR scan at all and also make sure no vulnerable images make it into the ECR storage. Worth future consideration

## Implementation notes

- BuildKit attestations silently disables scanning because it attaches a provenance attestation and wraps it in an OCI index, ECR's scanning cannot scan the index so the scan-on-push never happens. Error surfaced as a UnsupportedImageTypeException on a manual start-image-scan. The 856-byte manifest was the clue, and imageManifestMediaType confirmed it.
- Immutability is enforced at the tag not the blob, discovered when a change was uploaded and then the tag assignment was refused with the error: "cannot be overwritten because tag is immutable"
- Identical re-pushes are accepted as a no-op, same digest nothing else changed and no rejections. To test immutability needs a genuinely different image
- Two commits produced one image with two tags. Storage is content-addressed, so image count ≠ commit count. The lifecycle rule counts images.
- composer audit is time dependent. 'league/commonmark' advisories published Sept 30, failed in an unrelated workflow PR on Oct 10, because the dependencies needed an update but passed previously. Fixed by updating the package
- a completed scan with empty severity counts means clean. The field is omitted when there are zero findings, not reported as zero.

## Consequences

**Positive** —
- ECR images can be used to rollback because of their immutability
- Every image is immutable and can be traced back to one commit
- Storage is bounded - at most 10 tagged images are kept, untagged are removed after one day.
- OS Packages and PHP dependencies are both checked
- Relay-app repo and its images survives the destroy cycle, previous test showed them still existing after a destroy cycle and verified using 'describe-images'

**Negative** —
- ECR images are limited to 10 so this limits the ability to rollback beyond a certain point.
- Gave up provenance and sbom attestations (unsigned)
- composer audit can block a PR for reason unrelated to that PR.
- A bad image cannot be fixed in place; It takes a new commit and tag

**Accepted risks** —
- May possibly need to rollback further than the available ECR images would allow. Rollback window: revisit if deploy frequency rises enough that 10 images covers too short a period.
- Using the same CMK introduces the risk that if a role were ever granted access to both the state bucket and ECR, the same key would unlock both. If key is deleted then the state and images would become unreadable together. Prevent destroy and the 30 day deletion window mitigates this issue.
- Disabled Provenance: If Trivy-in-CI is adopted later or the need for signed attestations arises then can re-enable this
- Blocking audit: the mitigation is adding the composer ecosystem to Dependabot, still an open item

## Related
- 0005 (CMK)
- 0014 (The Image)
- 0016 (Build Role)
