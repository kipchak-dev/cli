<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold\Make;

use Kipchak\Cli\Scaffold\Validate\Inspection;

/**
 * How generated operations describe their responses. A project whose generator registers kipchak/core's Envelope
 * processor (starter 4.4 and later) declares only the data of a success, and an error response with no content; the
 * processor states the envelope around each. Any other project spells the envelope out, and refers to the shared
 * NotFound response, as starter 4.3 does.
 *
 * The short form is chosen only on evidence: the document the project's own generator produced holds the components
 * Envelope adds, and responses that refer to them. When the project cannot be inspected, or the evidence is missing,
 * the long form is written, which is correct with or without the processor.
 */
final readonly class ResponseStyle
{
    private function __construct(
        public bool $envelope,
        public bool $errorEnvelope,
    ) {}

    /**
     * The envelope and the NotFound reference written out, as starter 4.3 does.
     */
    public static function longForm(): self
    {
        return new self(false, false);
    }

    /**
     * Data only, as starter 4.4 does, for a project whose generator registers Envelope(errors: true).
     */
    public static function envelope(): self
    {
        return new self(true, true);
    }

    public static function fromInspection(?Inspection $inspection): self
    {
        if ($inspection === null || $inspection->document === null || $inspection->pipeline !== 'project' || !$inspection->envelopeAvailable) {
            return self::longForm();
        }
        $envelope = $inspection->hasEnvelopeComponent('Success');

        return new self($envelope, $envelope && $inspection->hasEnvelopeComponent('Error'));
    }

    /**
     * The content of a JSON success whose data is $schema, given as attribute arguments such as
     * "ref: '#/components/schemas/Order'", at the indent of a response's arguments.
     */
    public function content(string $oa, int $status, string $schema): string
    {
        $indent = '                ';
        if ($this->envelope) {
            return "{$indent}content: new {$oa}\\JsonContent({$schema}),";
        }

        return implode("\n", [
            "{$indent}content: new {$oa}\\JsonContent(properties: [",
            "{$indent}    new {$oa}\\Property(property: 'code', type: 'integer', example: {$status}),",
            "{$indent}    new {$oa}\\Property(property: 'status', type: 'string', example: '" . ($status === 201 ? 'CREATED' : 'OK') . "'),",
            "{$indent}    new {$oa}\\Property(property: 'data', {$schema}),",
            "{$indent}]),",
        ]);
    }

    /**
     * The 404 response of an operation on one item, at the indent of an operation's responses.
     */
    public function notFound(string $oa, string $description): string
    {
        return $this->errorEnvelope
            ? "            new {$oa}\\Response(response: 404, description: " . var_export($description, true) . '),'
            : "            new {$oa}\\Response(response: 404, ref: '#/components/responses/NotFound'),";
    }

    /**
     * The sentence a controller's docblock adds about the envelope; nothing in the long form.
     */
    public function controllerNote(): string
    {
        return $this->envelope
            ? " * A response declares only its data; the document states the envelope around it, and the error envelope for a\n"
                . " * 4xx that declares no content.\n"
            : '';
    }
}
