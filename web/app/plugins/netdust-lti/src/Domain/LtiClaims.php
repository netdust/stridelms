<?php
declare(strict_types=1);

namespace NetdustLTI\Domain;

final readonly class LtiClaims
{
    public function __construct(
        public string $sub,
        public ?string $email,
        public ?string $name,
        public ?string $givenName,
        public ?string $familyName,
        public ?string $contextId,
        public ?string $contextTitle,
        public ?string $resourceLinkId,
        public ?string $resourceLinkTitle,
        public array $roles,
        public array $custom,
        public ?string $lineItemUrl,
        public ?string $lineItemsUrl,
        public ?string $scoresUrl,
    ) {}

    public static function fromLtiTool(\ceLTIc\LTI\Tool $tool): self
    {
        $userResult = $tool->userResult;
        $context = $tool->context;
        $resourceLink = $tool->resourceLink;

        // Extract AGS endpoints from message parameters
        $agsEndpoint = $tool->messageParameters['https://purl.imsglobal.org/spec/lti-ags/claim/endpoint'] ?? [];

        return new self(
            sub: $userResult->ltiUserId ?? '',
            email: $userResult->email,
            name: $userResult->fullname,
            givenName: $userResult->firstname,
            familyName: $userResult->lastname,
            contextId: $context?->ltiContextId,
            contextTitle: $context?->title,
            resourceLinkId: $resourceLink?->ltiResourceLinkId,
            resourceLinkTitle: $resourceLink?->title,
            roles: $userResult->roles ?? [],
            custom: $resourceLink?->getSetting('custom') ?? [],
            lineItemUrl: $agsEndpoint['lineitem'] ?? null,
            lineItemsUrl: $agsEndpoint['lineitems'] ?? null,
            scoresUrl: $agsEndpoint['scores'] ?? null,
        );
    }

    public function isInstructor(): bool
    {
        foreach ($this->roles as $role) {
            if (str_contains($role, 'Instructor') || str_contains($role, 'Administrator')) {
                return true;
            }
        }
        return false;
    }

    public function isLearner(): bool
    {
        foreach ($this->roles as $role) {
            if (str_contains($role, 'Learner') || str_contains($role, 'Student')) {
                return true;
            }
        }
        return false;
    }

    public function getCourseId(): ?int
    {
        return isset($this->custom['ld_course_id']) ? (int) $this->custom['ld_course_id'] : null;
    }
}
