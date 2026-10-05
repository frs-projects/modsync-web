{{-- A search hit in the "Add from Modrinth/CurseForge" project picker. Inline styles: the panel has no theme build. --}}
<div style="display: flex; align-items: flex-start; gap: 0.75rem">
    @if ($project->iconUrl)
        <img src="{{ $project->iconUrl }}" alt="" referrerpolicy="no-referrer" loading="lazy" style="width: 2rem; height: 2rem; flex-shrink: 0; border-radius: 0.25rem">
    @else
        <div style="width: 2rem; height: 2rem; flex-shrink: 0; border-radius: 0.25rem; background: rgb(128 128 128 / 0.25)"></div>
    @endif
    <div style="min-width: 0">
        <div style="font-weight: 500">
            {{ $project->title }}
            @if ($installed)
                <span style="margin-inline-start: 0.25rem; font-size: 0.75rem; color: var(--primary-600)">{{ __('files.in_pack') }}</span>
            @endif
        </div>
        <div style="font-size: 0.75rem; opacity: 0.7">
            {{ collect([$project->author ? __('files.by', ['author' => $project->author]) : null, __('files.downloads', ['count' => \Illuminate\Support\Number::abbreviate($project->downloads)])])->filter()->implode(' · ') }}
        </div>
        @if ($project->summary)
            <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.75rem; opacity: 0.7">{{ $project->summary }}</div>
        @endif
    </div>
</div>
