<?php

namespace App\Livewire\Studio;

use App\Enums\ItpStage;
use App\Models\PromptVersion;
use App\Models\Staff;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One stage's prompt. Versions are immutable: saving always creates the next version and makes
 * it active, and rolling back makes an older version active again. Owner-only (`managePrompts`).
 *
 * Each version shows how many generations ran with it and the average cost of its calls
 * (`ai_usages.prompt_version_id`), so a prompt change can be judged on cost as well as output.
 */
#[Layout('layouts.studio')]
class PromptEditor extends Component
{
    #[Locked]
    public ItpStage $stage;

    public string $body = '';

    public string $notes = '';

    /**
     * The version open in the read-only viewer, if any.
     */
    #[Url(as: 'version')]
    public ?int $viewingId = null;

    public function mount(ItpStage $stage): void
    {
        $this->stage = $stage;
        $this->body = PromptVersion::activeFor($stage)->body ?? '';
    }

    /**
     * Save the editor as the stage's next version and make it active.
     *
     * @throws ValidationException
     */
    public function saveVersion(): void
    {
        $this->authorize('managePrompts');

        $this->validate([
            'body' => ['required', 'string', 'max:200000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($this->body === PromptVersion::activeFor($this->stage)?->body) {
            throw ValidationException::withMessages(['body' => 'This is the same as the active version. Change the prompt before saving.']);
        }

        /** @var Staff $staff */
        $staff = Auth::guard('studio')->user();

        $version = DB::transaction(function () use ($staff): PromptVersion {
            $version = PromptVersion::create([
                'stage' => $this->stage,
                'body' => $this->body,
                'notes' => filled($this->notes) ? $this->notes : null,
                'created_by_staff_id' => $staff->id,
            ]);

            $version->activate();

            return $version;
        });

        $this->reset('notes', 'viewingId');

        session()->flash('status', "Saved as v{$version->version}. It is active now.");
    }

    /**
     * Roll back (or forward): make another version of this stage active, and load it into the
     * editor.
     */
    public function activate(int $versionId): void
    {
        $this->authorize('managePrompts');

        $version = $this->findVersion($versionId);
        $version->activate();

        $this->body = $version->body;
        $this->reset('notes');

        session()->flash('status', "v{$version->version} is active now.");
    }

    public function view(int $versionId): void
    {
        $this->viewingId = $this->findVersion($versionId)->id;
    }

    public function closeViewer(): void
    {
        $this->reset('viewingId');
    }

    public function render(): View
    {
        return view('livewire.studio.prompt-editor', [
            'versions' => $this->versions(),
            'viewing' => $this->viewingId === null ? null : PromptVersion::query()->where('stage', $this->stage)->find($this->viewingId),
        ])->title($this->stage->label().' prompt');
    }

    /**
     * Every version, newest first, with how often it ran and what its calls cost on average.
     *
     * @return Collection<int, PromptVersion>
     */
    protected function versions(): Collection
    {
        return PromptVersion::query()
            ->where('stage', $this->stage)
            ->with('createdBy')
            ->withCount(['aiUsages as calls_count'])
            ->withCount(['aiUsages as generations_count' => fn ($query) => $query->select(DB::raw('count(distinct generation_id)'))])
            ->withAvg('aiUsages as average_cost', 'cost_usd')
            ->orderByDesc('version')
            ->get();
    }

    protected function findVersion(int $versionId): PromptVersion
    {
        return PromptVersion::query()->where('stage', $this->stage)->findOrFail($versionId);
    }
}
