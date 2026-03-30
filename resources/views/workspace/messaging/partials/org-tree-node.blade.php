@php
    /** @var array<string, mixed> $node */
    $children = collect($node['children'] ?? [])->values();
    $hasChildren = $children->isNotEmpty();
    $nodeType = (string) ($node['type'] ?? 'group');
    $nodeKey = (string) ($node['key'] ?? uniqid('org-node-', false));
    $isUserNode = in_array($nodeType, ['user', 'root_user'], true);
    $isSelected = $isUserNode && (int) ($node['user_id'] ?? 0) === (int) ($selectedUserId ?? 0);
    $panelId = 'org-tree-panel-'.$nodeKey;
    $theme = (string) ($node['theme'] ?? 'blue');
    $hierarchyLevel = (string) ($node['hierarchy_level'] ?? 'agent');
    $nodeSectionLabel = match (true) {
        $nodeType === 'root', $nodeType === 'root_user' => 'DG',
        $nodeType === 'direction' => 'Direction',
        $nodeType === 'service' => 'Service',
        $hierarchyLevel === 'direction' => 'Direction',
        $hierarchyLevel === 'service' => 'Service',
        default => 'Agent',
    };
    $childSectionLabel = match ($nodeType) {
        'root', 'root_user' => 'Directions',
        'direction' => 'Services',
        'service' => 'Agents',
        default => '',
    };
    $compactRoleLabel = match ((string) ($node['role_label'] ?? '')) {
        'Administrateur' => 'ADMIN',
        'CABINET' => 'CAB',
        'PLANIFICATION' => 'PLAN',
        'DIRECTION' => 'DIR',
        'SERVICES' => 'SERV',
        'AGENT' => 'AGT',
        default => (string) ($node['role_label'] ?? $node['presence'] ?? 'PROFIL'),
    };
    $managerName = trim((string) ($node['manager_name'] ?? ''));
    $managerTitle = trim((string) ($node['manager_title'] ?? ''));
    $managerUserId = (int) ($node['manager_user_id'] ?? 0);
    $managerLevel = (string) ($node['manager_level'] ?? 'agent');
    $managerPhotoUrl = (string) ($node['manager_photo_url'] ?? '');
    $managerInitials = trim((string) ($node['manager_initials'] ?? ''));
    $detail = trim((string) ($node['detail'] ?? ''));
    $routeParams = array_filter([
        'contact' => $isUserNode ? (int) ($node['user_id'] ?? 0) : null,
        'conversation' => ! empty($activeConversationId) ? (int) $activeConversationId : null,
    ], static fn ($value): bool => $value !== null && $value !== 0);
    $profileCardParams = array_filter([
        'target' => $isUserNode ? (int) ($node['user_id'] ?? 0) : null,
        'conversation' => ! empty($activeConversationId) ? (int) $activeConversationId : null,
    ], static fn ($value): bool => $value !== null && $value !== 0);
    $managerRouteParams = array_filter([
        'contact' => $managerUserId > 0 ? $managerUserId : null,
        'conversation' => ! empty($activeConversationId) ? (int) $activeConversationId : null,
    ], static fn ($value): bool => $value !== null && $value !== 0);
    $managerProfileCardParams = array_filter([
        'target' => $managerUserId > 0 ? $managerUserId : null,
        'conversation' => ! empty($activeConversationId) ? (int) $activeConversationId : null,
    ], static fn ($value): bool => $value !== null && $value !== 0);
    $toneClass = match ((string) ($node['tone'] ?? 'neutral')) {
        'success' => 'anbg-badge anbg-badge-success',
        'info' => 'anbg-badge anbg-badge-info',
        'warning' => 'anbg-badge anbg-badge-warning',
        'danger', 'critical' => 'anbg-badge anbg-badge-danger',
        default => 'anbg-badge anbg-badge-neutral',
    };
    $searchText = Str::lower(implode(' ', array_filter([
        $nodeSectionLabel,
        (string) ($node['label'] ?? ''),
        (string) ($node['subtitle'] ?? ''),
        (string) ($node['scope'] ?? ''),
        (string) ($node['role_label'] ?? ''),
        (string) ($node['presence'] ?? ''),
        (string) ($node['emphasis'] ?? ''),
        $managerName,
        $managerTitle,
        $detail,
        $childSectionLabel,
    ], static fn (?string $value): bool => filled($value))));
@endphp

<li
    class="messaging-org-tree-item node-{{ $nodeType }} theme-{{ $theme }} {{ $isUserNode ? 'level-'.$hierarchyLevel : '' }} {{ $hasChildren && empty($node['expanded']) ? 'is-collapsed' : '' }}"
    data-org-tree-item="1"
    data-org-search-text="{{ e($searchText) }}"
    @if ($hasChildren) data-org-branch="1" @endif
>
    @if ($isUserNode)
        <a
            href="{{ route('workspace.messaging.index', $routeParams) }}#messaging-profile-card"
            class="messaging-org-tree-node is-user level-{{ $hierarchyLevel }} {{ $isSelected ? 'is-selected' : '' }}"
            data-org-user-node="1"
            data-org-selected="{{ $isSelected ? '1' : '0' }}"
            data-user-id="{{ (int) ($node['user_id'] ?? 0) }}"
            data-profile-card-link="1"
            data-profile-card-url="{{ route('workspace.messaging.profile.card', $profileCardParams) }}"
        >
            <span class="messaging-org-tree-presence {{ 'tone-'.($node['tone'] ?? 'neutral') }}" aria-hidden="true"></span>
            <span class="messaging-org-tree-person-badge">{{ $compactRoleLabel }}</span>
            <div class="messaging-org-tree-avatar-shell">
                @if (! empty($node['photo_url']))
                    <img src="{{ $node['photo_url'] }}" alt="{{ $node['label'] }}" class="messaging-org-tree-avatar">
                @else
                    <span class="messaging-org-tree-avatar-fallback">{{ $node['initials'] ?? '?' }}</span>
                @endif
            </div>
            <div class="messaging-org-tree-copy">
                <p class="messaging-org-tree-node-kicker" data-org-highlight-source="1">{{ $nodeSectionLabel }}</p>
                <p class="messaging-org-tree-title" data-org-highlight-source="1">{{ $node['label'] }}</p>
                <p class="messaging-org-tree-subtitle" data-org-highlight-source="1">{{ $node['subtitle'] }}</p>
                <p class="messaging-org-tree-meta" data-org-highlight-source="1">{{ $node['scope'] }}</p>
                <div class="messaging-org-tree-badges">
                    <span class="{{ $toneClass }} px-2 py-0.5 text-[10px]">{{ $node['presence'] }}</span>
                    @if (! empty($node['emphasis']))
                        <span class="anbg-badge anbg-badge-warning px-2 py-0.5 text-[10px]">{{ $node['emphasis'] }}</span>
                    @endif
                </div>
            </div>
            <span class="messaging-org-tree-action">
                {{ ! empty($node['is_current_user']) ? 'Mon profil' : 'Profil & message' }}
            </span>
        </a>

        @if ($hasChildren)
            <div
                id="{{ $panelId }}"
                class="messaging-org-tree-children"
                data-org-branch-panel="1"
            >
                @if ($childSectionLabel !== '')
                    <div class="messaging-org-tree-section-label">{{ $childSectionLabel }}</div>
                @endif
                <ul class="messaging-org-tree-list {{ $children->count() === 1 ? 'has-single-child' : '' }}" role="tree">
                    @foreach ($children as $child)
                        @include('workspace.messaging.partials.org-tree-node', [
                            'node' => $child,
                            'selectedUserId' => $selectedUserId,
                            'activeConversationId' => $activeConversationId,
                        ])
                    @endforeach
                </ul>
            </div>
        @endif
    @else
        <div class="messaging-org-tree-branch">
            <div class="messaging-org-tree-toggle">
                <button
                    type="button"
                    class="messaging-org-tree-branch-toggle"
                    data-org-branch-toggle="1"
                    aria-expanded="{{ ! empty($node['expanded']) ? 'true' : 'false' }}"
                    aria-controls="{{ $panelId }}"
                >
                    <span class="messaging-org-tree-chevron" aria-hidden="true"></span>
                </button>
                <span class="messaging-org-tree-copy">
                    <span class="messaging-org-tree-node-kicker" data-org-highlight-source="1">{{ $nodeSectionLabel }}</span>
                    <span class="messaging-org-tree-title" data-org-highlight-source="1">{{ $node['label'] }}</span>
                    @if (! empty($node['subtitle']))
                        <span class="messaging-org-tree-subtitle" data-org-highlight-source="1">{{ $node['subtitle'] }}</span>
                    @endif
                    @if ($managerName !== '')
                        @if ($managerUserId > 0)
                            <a
                                href="{{ route('workspace.messaging.index', $managerRouteParams) }}#messaging-profile-card"
                                class="messaging-org-tree-manager-link level-{{ $managerLevel }} {{ $managerUserId === (int) ($selectedUserId ?? 0) ? 'is-selected' : '' }}"
                                data-org-user-node="1"
                                data-org-selected="{{ $managerUserId === (int) ($selectedUserId ?? 0) ? '1' : '0' }}"
                                data-user-id="{{ $managerUserId }}"
                                data-profile-card-link="1"
                                data-profile-card-url="{{ route('workspace.messaging.profile.card', $managerProfileCardParams) }}"
                            >
                                <span class="messaging-org-tree-manager-avatar">
                                    @if ($managerPhotoUrl !== '')
                                        <img src="{{ $managerPhotoUrl }}" alt="{{ $managerName }}" class="messaging-org-tree-avatar">
                                    @else
                                        <span class="messaging-org-tree-avatar-fallback">{{ $managerInitials !== '' ? $managerInitials : 'NA' }}</span>
                                    @endif
                                </span>
                                <span class="messaging-org-tree-structure-lead">
                                    <span class="messaging-org-tree-structure-kicker">Responsable</span>
                                    <strong data-org-highlight-source="1">{{ $managerName }}</strong>
                                    @if ($managerTitle !== '')
                                        <em data-org-highlight-source="1">{{ $managerTitle }}</em>
                                    @endif
                                </span>
                            </a>
                        @else
                            <span class="messaging-org-tree-structure-lead">
                                <span class="messaging-org-tree-structure-kicker">Responsable</span>
                                <strong data-org-highlight-source="1">{{ $managerName }}</strong>
                                @if ($managerTitle !== '')
                                    <em data-org-highlight-source="1">{{ $managerTitle }}</em>
                                @endif
                            </span>
                        @endif
                    @endif
                    @if ($detail !== '')
                        <span class="messaging-org-tree-meta" data-org-highlight-source="1">{{ $detail }}</span>
                    @endif
                </span>
                @if (isset($node['count']))
                    <span class="messaging-org-tree-count">{{ (int) $node['count'] }}</span>
                @endif
            </div>
        </div>

        @if ($hasChildren)
            <div
                id="{{ $panelId }}"
                class="messaging-org-tree-children"
                data-org-branch-panel="1"
            >
                @if ($childSectionLabel !== '')
                    <div class="messaging-org-tree-section-label">{{ $childSectionLabel }}</div>
                @endif
                <ul class="messaging-org-tree-list {{ $children->count() === 1 ? 'has-single-child' : '' }}" role="tree">
                    @foreach ($children as $child)
                        @include('workspace.messaging.partials.org-tree-node', [
                            'node' => $child,
                            'selectedUserId' => $selectedUserId,
                            'activeConversationId' => $activeConversationId,
                        ])
                    @endforeach
                </ul>
            </div>
        @endif
    @endif
</li>
