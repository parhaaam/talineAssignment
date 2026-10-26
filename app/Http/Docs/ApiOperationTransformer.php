<?php

namespace App\Http\Docs;

use App\Exceptions\{DomainException, Unauthenticated};
use App\Http\Middleware\ResolveUser;
use Dedoc\Scramble\Support\Generator\{Operation, Parameter, Reference, Response, Schema, Types as OpenApiTypes};
use Dedoc\Scramble\Support\RouteInfo;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use ReflectionClass;

/**
 * Completes what Scramble infers from the controllers' PHPDoc:
 *  - `@throws SomeDomainException` becomes the error response the shared handler returns, grouped
 *    by HTTP status so several errors can share one status code;
 *  - `@status 201` sets the success status (the `@response` tag only describes the body);
 *  - the `X-User-Id` and `Idempotency-Key` headers are documented as headers.
 */
class ApiOperationTransformer
{
    public function __invoke(Operation $operation, RouteInfo $routeInfo): void
    {
        $authenticated = in_array(ResolveUser::class, $routeInfo->route->gatherMiddleware(), true);

        $this->addHeaders($operation, $routeInfo, $authenticated);
        $this->applySuccessStatus($operation, $routeInfo);
        $this->replaceErrorResponses($operation, $routeInfo, $authenticated);
    }

    private function addHeaders(Operation $operation, RouteInfo $routeInfo, bool $authenticated): void
    {
        if ($authenticated) {
            $operation->addParameters([
                Parameter::make('X-User-Id', 'header')
                    ->required(true)
                    ->description('Id of the authenticated user. Authentication is out of scope, so this header stands in for it; a missing or unknown id returns 401.')
                    ->setSchema(Schema::fromType(new OpenApiTypes\StringType())),
            ]);
        }

        if ($operation->method === 'post') {
            $operation->addParameters([
                Parameter::make('Idempotency-Key', 'header')
                    ->required(true)
                    ->description('Client-chosen key (max 128 printable ASCII characters), unique per user and operation. Retrying with the same key and payload returns the original outcome; the same key with a different payload returns 409.')
                    ->setSchema(Schema::fromType(new OpenApiTypes\StringType())),
            ]);
        }
    }

    private function applySuccessStatus(Operation $operation, RouteInfo $routeInfo): void
    {
        $tag = array_values($routeInfo->phpDoc()->getTagsByName('@status'))[0] ?? null;

        if ($tag === null) {
            return;
        }

        foreach ($operation->responses as $response) {
            if ($response instanceof Response && (int) $response->code === 200) {
                $response->setCode((int) trim((string) $tag->value));
            }
        }
    }

    private function replaceErrorResponses(Operation $operation, RouteInfo $routeInfo, bool $authenticated): void
    {
        /** @var array<int, list<DomainException>> $byStatus */
        $byStatus = [];

        $exceptions = $this->thrownExceptions($routeInfo);
        if ($authenticated) {
            $exceptions[] = new Unauthenticated();
        }
        foreach ($exceptions as $exception) {
            $byStatus[$exception->status][] = $exception;
        }

        $hasValidation = collect($operation->responses)->contains(fn ($r) => $this->statusOf($r) === 422);

        // Scramble's own error responses (e.g. Laravel's validation shape) are replaced by this API's shape.
        $operation->responses = collect($operation->responses)
            ->reject(fn ($r) => $this->statusOf($r) >= 400)
            ->values()
            ->all();

        ksort($byStatus);
        $statuses = array_unique([...array_keys($byStatus), ...($hasValidation ? [422] : [])]);
        sort($statuses);

        foreach ($statuses as $status) {
            $operation->addResponse($this->errorResponse($status, $byStatus[$status] ?? [], $hasValidation && $status === 422));
        }
    }

    /** Scramble keeps shared error responses as references; resolve them to read the status. */
    private function statusOf(Response|Reference $response): int
    {
        $resolved = $response instanceof Reference ? $response->resolve() : $response;

        return $resolved instanceof Response ? (int) $resolved->code : 0;
    }

    /**
     * @return list<DomainException>
     */
    private function thrownExceptions(RouteInfo $routeInfo): array
    {
        $exceptions = [];

        foreach ($routeInfo->phpDoc()->getThrowsTagValues() as $throws) {
            if (! $throws->type instanceof IdentifierTypeNode) {
                continue;
            }

            $class = ltrim($throws->type->name, '\\');
            $class = class_exists($class) ? $class : 'App\\Exceptions\\'.$class;

            if (class_exists($class) && is_subclass_of($class, DomainException::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $exceptions[] = new $class();
            }
        }

        return $exceptions;
    }

    /**
     * @param  list<DomainException>  $exceptions
     */
    private function errorResponse(int $status, array $exceptions, bool $withValidation): Response
    {
        $lines = [];
        $codes = [];

        foreach ($exceptions as $exception) {
            $codes[] = $exception->errorCode;
            $lines[] = "- `{$exception->errorCode}`: {$exception->getMessage()}";
        }

        $error = (new OpenApiTypes\ObjectType())
            ->addProperty('code', $this->codeType($codes, $withValidation))
            ->addProperty('message', new OpenApiTypes\StringType())
            ->setRequired(['code', 'message']);

        if ($withValidation) {
            $lines[] = '- `validation_failed`: The request is invalid; `fields` maps each invalid field to its messages.';
            $error->addProperty(
                'fields',
                (new OpenApiTypes\ObjectType())
                    ->setDescription('Present for `validation_failed`: field name to list of messages.')
                    ->additionalProperties((new OpenApiTypes\ArrayType())->setItems(new OpenApiTypes\StringType())),
            );
        }

        return Response::make($status)
            ->setDescription(implode("\n", $lines))
            ->setContent('application/json', Schema::fromType(
                (new OpenApiTypes\ObjectType())->addProperty('error', $error)->setRequired(['error']),
            ));
    }

    /**
     * @param  list<string>  $codes
     */
    private function codeType(array $codes, bool $withValidation): OpenApiTypes\StringType
    {
        if ($withValidation) {
            $codes[] = 'validation_failed';
        }

        return count($codes) > 0 ? (new OpenApiTypes\StringType())->enum(array_values(array_unique($codes))) : new OpenApiTypes\StringType();
    }
}
