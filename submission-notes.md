Submission Notes



1\. Purpose of the project

`ai\\\_release\\\_guardian` is a Drupal 11 custom module that performs an

LLM-assisted audit of pending configuration imports before they reach

production. It runs as a Drush command so it can be wired into CI/CD,

plus a small admin dashboard for browsing audit history.

The reason it exists, in nine years of Drupal work the worst production

incidents I've seen have almost all come from configuration deploys, not

code deploys. Someone leaves `devel` enabled in the export, or grants a

broad permission to the wrong role, or flips a cache off, and it gets

through code review because YAML diffs are noisy and reviewers' eyes

skim. A 600 ms second-opinion in front of `drush config:import` would

have caught most of them. That's the gap this module fills.







2\. Key architectural / coding decisions.



* Drush-first, dashboard-second. The audit is meant to run in pipelines. The web UI only shows past results, it does not start audits.
* API key stays in State, not Config. Config can be exported to git. State is environment-specific, so credentials stay out of exports.
* Provider failure is safe. If the AI call fails, the audit returns UNVERIFIED. That avoids a Groq outage turning into a hidden deployment pass.
* Cache by diff content. Identical pending config diffs reuse the same audit result. The cache is invalidated if policy settings change.
* Redact sensitive data before sending. Secret-like fields, long strings, and emails are redacted first. The AI still sees the config shape, but not the sensitive content.
* Schema-backed config. Settings are defined in config/schema/ai\_release\_guardian.schema.yml. Drupal validates them during config import, not only at form submit.
* Dependency injection everywhere. Services are wired through YAML. The code avoids static service lookups.
* Typed objects instead of raw arrays. Findings, verdicts, and results use PHP value objects and enums. That improves safety and IDE support.



Trade-offs intentionally avoided.



* No AI provider plugin manager. One service with a configurable base URL is enough for OpenAI-compatible providers. If a non-compatible provider is needed later, extract a plugin layer then.
* No custom audit-log entity. Audit history is kept in a small State ring buffer (50 entries). That fits the actual usage pattern and keeps the module simpler.





3.AI Tools Used During Development



GitHub Copilot was the primary AI tool used during development as an inline code assistant inside the IDE. It helped speed up repetitive tasks such as creating service definitions, schema YAML structures, template scaffolding, and PHPDoc comments. All AI-generated suggestions were manually reviewed and only accepted when they followed Drupal best practices and coding standards. For example, suggestions using static service calls like \\Drupal::service() were avoided in favor of dependency injection.



The overall architecture and design decisions were made manually based on real-world Drupal development experience. This includes:

* storing API keys securely using the State API,
* implementing fallback handling for AI provider failures,
* adding PII sanitization,
* using caching for repeated audits,
* and designing CI/CD-friendly audit exit codes.



Code Review \& Quality Assurance:

* Drupal Conventions: Ensured alignment with platform best practices.
* Security: Verified robust input validation, secret handling, and anti-CSRF measures.
* Performance: Confirmed cache tags are wired correctly to eliminate N+1 queries.
* Accessibility: Checked Twig templates for semantic HTML, and ensured verdict indicators use clear text labels and icons rather than color alone.

