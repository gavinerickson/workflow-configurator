<?php

namespace WorkflowConfigurator\Entity;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Exposes the JSON metadata column as a string for admin forms. Invalid JSON
 * is held (not applied) and reported as a validation error instead of an
 * exception.
 */
trait MetadataJsonTrait
{
    private ?string $invalidMetadataJson = null;

    public function getMetadataJson(): string
    {
        if (null !== $this->invalidMetadataJson) {
            return $this->invalidMetadataJson;
        }

        if ([] === $this->metadata) {
            return '';
        }

        return json_encode($this->metadata, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    public function setMetadataJson(?string $json): static
    {
        $this->invalidMetadataJson = null;

        if (null === $json || '' === trim($json)) {
            $this->metadata = [];

            return $this;
        }

        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->invalidMetadataJson = $json;

            return $this;
        }

        if (!\is_array($decoded)) {
            $this->invalidMetadataJson = $json;

            return $this;
        }

        $this->metadata = $decoded;

        return $this;
    }

    #[Assert\Callback]
    public function validateMetadataJson(ExecutionContextInterface $context): void
    {
        if (null !== $this->invalidMetadataJson) {
            $context->buildViolation('This is not a valid JSON object, e.g. {"task": "mailmark_stamp"}.')
                ->atPath('metadataJson')
                ->addViolation();
        }
    }
}
