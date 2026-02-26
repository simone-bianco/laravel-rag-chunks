<?php

namespace SimoneBianco\LaravelRagChunks\Models\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use SimoneBianco\LaravelRagChunks\Models\Relation;
use SimoneBianco\LaravelRagChunks\Enums\RelationType;

trait InteractsWithRagRelations
{
    /*
    |--------------------------------------------------------------------------
    | RELAZIONI ELOQUENT BASE
    |--------------------------------------------------------------------------
    */

    public function outgoingRelations(): MorphMany
    {
        return $this->morphMany(Relation::class, 'from_entity');
    }

    public function incomingRelations(): MorphMany
    {
        return $this->morphMany(Relation::class, 'to_entity');
    }

    /*
    |--------------------------------------------------------------------------
    | CREAZIONE RELAZIONI
    |--------------------------------------------------------------------------
    */

    /**
     * Crea una relazione UNIDIREZIONALE (A -> B).
     */
    public function relateUnidirectionallyTo(Model $target, ?string $name = null, ?string $description = null): Relation
    {
        return $this->outgoingRelations()->create([
            'to_entity_id'   => $target->getKey(),
            'to_entity_type' => $target->getMorphClass(),
            'type'           => RelationType::UNIDIRECTIONAL,
            'name'           => $name,
            'description'    => $description,
        ]);
    }

    /**
     * Crea una relazione BIDIREZIONALE (A <-> B).
     */
    public function relateBidirectionallyTo(Model $target, ?string $name = null, ?string $description = null): Relation
    {
        return $this->outgoingRelations()->create([
            'to_entity_id'   => $target->getKey(),
            'to_entity_type' => $target->getMorphClass(),
            'type'           => RelationType::BIDIRECTIONAL,
            'name'           => $name,
            'description'    => $description,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFICA RELAZIONI (Con logica polimorfica per le bidirezionali)
    |--------------------------------------------------------------------------
    */

    /**
     * Verifica se questo modello è relazionato al target.
     * Ritorna true se:
     * 1. Abbiamo una relazione in uscita verso il target (qualsiasi tipo).
     * 2. Il target ha una relazione verso di noi di tipo BIDIREZIONALE.
     */
    public function isRelatedTo(Model $target): bool
    {
        // Controllo 1: Relazioni in uscita (siamo noi a puntare al target)
        $hasOutgoing = $this->outgoingRelations()
            ->where('to_entity_id', $target->getKey())
            ->where('to_entity_type', $target->getMorphClass())
            ->exists();

        if ($hasOutgoing) {
            return true;
        }

        // Controllo 2: Relazioni in entrata (il target punta a noi, ma deve essere BIDIRECTIONAL)
        return $this->incomingRelations()
            ->where('from_entity_id', $target->getKey())
            ->where('from_entity_type', $target->getMorphClass())
            ->where('type', RelationType::BIDIRECTIONAL)
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | RIMOZIONE RELAZIONI
    |--------------------------------------------------------------------------
    */

    /**
     * Rimuove qualsiasi relazione tra questo modello e il target (in entrambe le direzioni).
     */
    public function unrelate(Model $target): int
    {
        $deletedOutgoing = $this->outgoingRelations()
            ->where('to_entity_id', $target->getKey())
            ->where('to_entity_type', $target->getMorphClass())
            ->delete();

        $deletedIncoming = $this->incomingRelations()
            ->where('from_entity_id', $target->getKey())
            ->where('from_entity_type', $target->getMorphClass())
            ->where('type', RelationType::BIDIRECTIONAL) // Opzionale: puoi togliere la clausola where se vuoi distruggere tutto
            ->delete();

        return $deletedOutgoing + $deletedIncoming;
    }

    /*
    |--------------------------------------------------------------------------
    | RECUPERO ENTITÀ CONNESSE
    |--------------------------------------------------------------------------
    */

    /**
     * Recupera tutte le entità collegate, gestendo correttamente la bidirezionalità.
     */
    public function getConnectedEntities(): Collection
    {
        // 1. Entità verso cui puntiamo noi (valgono tutte: sia uni che bi)
        $outgoing = $this->outgoingRelations()
            ->with('to_entity')
            ->get()
            ->pluck('to_entity')
            ->filter();

        // 2. Entità che puntano a noi (valgono SOLO se bidirezionali)
        $incomingBidirectional = $this->incomingRelations()
            ->where('type', RelationType::BIDIRECTIONAL)
            ->with('from_entity')
            ->get()
            ->pluck('from_entity')
            ->filter();

        // Uniamo le due collection e rimuoviamo eventuali duplicati
        return $outgoing->merge($incomingBidirectional)->unique(function (Model $item) {
            return $item->getMorphClass() . $item->getKey();
        })->values();
    }
}
