<?php
declare(strict_types=1);

namespace NetdustLTI\Shared\Domain;

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

    /**
     * Create LtiClaims from Tool with pre-extracted custom params.
     * This is the preferred method since messageParameters is protected in ceLTIc.
     */
    public static function fromLtiToolWithParams(
        \ceLTIc\LTI\Tool $tool,
        array $customParams,
        array $agsEndpoint = []
    ): self {
        $userResult = $tool->userResult;
        $context = $tool->context;
        $resourceLink = $tool->resourceLink;

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
            custom: $customParams,
            lineItemUrl: $agsEndpoint['lineitem'] ?? null,
            lineItemsUrl: $agsEndpoint['lineitems'] ?? null,
            scoresUrl: $agsEndpoint['scores'] ?? null,
        );
    }

    /**
     * @deprecated Use fromLtiToolWithParams instead - this can't access protected properties
     */
    public static function fromLtiTool(\ceLTIc\LTI\Tool $tool): self
    {
        // Fallback that won't work well due to protected properties
        return self::fromLtiToolWithParams($tool, [], []);
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
