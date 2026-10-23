<x-panel title="Latest evaluations" subtitle="Selected session · current authorization · one recorded outcome per symbol and five-minute cycle" icon="shield" flush id="paper-signals">
    <div class="table-wrap"><table class="data"><thead><tr><th>Ticker / outcome</th><th>Reason</th><th>Evaluated (Central)</th><th>Recorded blockers / rule</th></tr></thead><tbody>
        @forelse($data['signals'] as $signal)<tr>
            <td><strong>{{ $signal->symbol }}</strong><small class="block"><x-pill :value="$signal->status" /> {{ $signal->side }}</small></td>
            <td>{{ App\Support\Mcp\DisplayFormat::humanize($signal->reason) }}</td><td><x-time :at="$signal->cutoff" :relative="false" /></td>
            <td><details><summary>Inspect recorded checks</summary><ul class="bullets">@forelse($signal->payload['evidence']['report']['eligibility']['checks'] ?? [] as $name => $check)<li><strong>{{ ucfirst($name) }}:</strong> {{ $check['status'] }} · {{ App\Support\Mcp\DisplayFormat::humanize($check['reason'] ?? 'not_recorded') }}</li>@empty<li>No entry eligibility report for this evaluation; position exits and broker-held positions use separate checks.</li>@endforelse</ul>
                <p class="note">{{ $signal->rule_version }} · {{ $signal->parameters_version }} · signal #{{ $signal->id }}</p></details></td>
        </tr>@empty<tr><td colspan="4" class="empty-cell">No evaluations in this session for this ticker filter.</td></tr>@endforelse
    </tbody></table></div>{{ $data['signals']->fragment('paper-signals')->links('mcp.pagination') }}
</x-panel>
