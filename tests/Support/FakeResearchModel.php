<?php

namespace Tests\Support;

use App\Services\Research\ModelRequest;
use App\Services\Research\ModelResponse;
use App\Services\Research\ResearchModel;

/** Synthetic model. It never performs network I/O and has no tools. */
final class FakeResearchModel implements ResearchModel
{
    /** @var list<ModelRequest> */
    public array $requests = [];

    /** @var list<ModelResponse|\Throwable|\Closure> */
    public array $responses = [];

    public ?string $log = null;

    public int $sleepMs = 0;

    public bool $reportUsage = true;

    public function complete(ModelRequest $request): ModelResponse
    {
        $this->requests[] = $request;
        if ($this->log) {
            file_put_contents($this->log, "call\n", FILE_APPEND | LOCK_EX);
        }
        if ($this->sleepMs) {
            usleep($this->sleepMs * 1000);
        }
        $next = array_shift($this->responses) ?? fn (ModelRequest $r) => self::answer($r, reportUsage: $this->reportUsage);
        if ($next instanceof \Closure) {
            $next = $next($request);
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /** A valid response citing the first supplied source; $mutate edits the decoded answer. */
    public static function answer(ModelRequest $request, ?callable $mutate = null, bool $reportUsage = true): ModelResponse
    {
        $context = self::context($request);
        $source = $context['sources'][0] ?? null;
        $data = ['thesis_assessment' => $context['thesis'] ? ($source ? 'supported' : 'insufficient_evidence') : 'no_thesis',
            'summary' => 'Synthetic research summary for fixture testing only.',
            'material_facts' => $source ? [['statement' => 'Synthetic fact drawn from the supplied fixture source.', 'source_ids' => [$source['id']], 'as_of' => $source['as_of']]] : [],
            'uncertainties' => ['Synthetic uncertainty.'],
            'proposal' => ['stance' => 'wait', 'symbol' => $context['subject'], 'catalyst' => null, 'risks' => ['Synthetic risk.'], 'horizon' => 'weeks',
                'invalidation' => ['type' => 'manual_review', 'operator' => null, 'threshold' => null, 'description' => 'Synthetic review condition.'],
                'review_at' => now()->addDays(7)->utc()->format(DATE_ATOM)]];
        if ($mutate) {
            $data = $mutate($data, $context);
        }

        return new ModelResponse('completed', json_encode($data), $reportUsage ? 1000 : null, $reportUsage ? 200 : null, $request->model);
    }

    public static function text(string $text): ModelResponse
    {
        return new ModelResponse('completed', $text, 900, 100, 'synthetic-model');
    }

    public static function context(ModelRequest $request): array
    {
        preg_match('/<untrusted_data>\n(.*?)\n<\/untrusted_data>/s', $request->input, $match);

        return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
    }
}
