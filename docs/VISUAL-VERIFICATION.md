# Rendered Visual Verification

Cresco Layer 0.20 adds a post-apply verification layer for semantic design mutations.

Persistence verification answers: **Did Elementor save the reviewed settings?** Rendered verification answers a different question: **Did the current Elementor preview render the semantic design intent in a compatible way?**

## Workflow

After a successful semantic mutation apply, Cresco keeps the original `cresco-ai-mutation/v3` or v2 payload plus the final temporary-ref-to-Elementor-ID mapping. The Import panel enables **Verify Render**. The verifier then reads the same-origin Elementor preview iframe and compares semantic intent with computed rendering.

Schema:

```text
cresco-visual-verification/v1
```

## Current checks

Version 1 checks properties that can be measured deterministically without claiming image understanding:

- flex direction, justification, alignment and wrapping;
- gap, width, min-height, max-width, padding/margin approximations;
- overflow;
- border radius and opacity;
- text alignment, font size, line height, letter spacing and weight;
- background/text colors when they can be normalized by the browser;
- explicit ARIA label intent;
- decorative accessibility intent where detectable;
- touch-target warning for CTA/button-like nodes;
- horizontal overflow warning.

Results are `pass`, `partial`, `mismatch` or `unavailable`, with per-check expected and actual values.

## Scope and limitations

This is intentionally **not** a pixel-perfect screenshot diff. CSS percentages, flex sizing, fonts, media loading and browser layout can resolve semantic values into different computed units, so several checks are warnings/tolerance-based rather than hard failures.

Reference-image similarity remains an external-model task using the AI Bundle raster/reference assets. Cresco's local verifier is a deterministic safety/quality check for rendered geometry and computed styles.

If the preview iframe is unavailable or a rendered node cannot be resolved, verification reports `unavailable` rather than inventing a result.

## Safety

The verifier is read-only. It does not auto-repair a page. Any repair remains a new reviewed mutation so the normal runtime validation, SemanticPatchGuard, preview and persistence verification still apply.
