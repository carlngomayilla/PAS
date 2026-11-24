<td>
    <strong>{{ $title }}</strong>
    @if($receipt)
        <img class="signature-image" src="{{ $receipt->signature_snapshot }}" alt="Signature de {{ $receipt->actor_name }}">
        <div class="signature-name">{{ $receipt->actor_name }}</div>
        <div class="signature-date">
            {{ $receipt->actor_matricule ? 'Matricule '.$receipt->actor_matricule.' — ' : '' }}
            signé le {{ $receipt->signed_at?->format('d/m/Y à H:i') }}
        </div>
    @else
        <div class="missing">Visa non enregistré pour cette version.</div>
    @endif
</td>
