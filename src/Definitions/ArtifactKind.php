<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Definitions;

enum ArtifactKind: string
{
    case Livewire = 'livewire';
    case Action = 'action';
    case View = 'view';
    case FeatureTest = 'feature-test';
}
