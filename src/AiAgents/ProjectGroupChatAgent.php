<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LarAgent\Core\Contracts\DataModel;
use LarAgent\Core\Contracts\Message as MessageInterface;
use Psr\Log\LoggerInterface;
use SimoneBianco\LaravelRagChunks\AiAgents\Concerns\NormalizesChunkIds;
use SimoneBianco\LaravelRagChunks\AiAgents\History\PageChatStorageDriver;
use SimoneBianco\LaravelRagChunks\AiAgents\Tools\SearchInAllowedProjects;
use SimoneBianco\LaravelRagChunks\Models\Chunk;
use SimoneBianco\LaravelRagChunks\Models\ProjectGroup;

/**
 * @deprecated Use {@see \SimoneBianco\LaravelAiAgents\Services\AgentInstantiationService}
 *             with the seeded slug `project-group-chat-default`. This hand-written agent will
 *             be removed once all consumers have migrated to the database-driven agent pipeline.
 */
class ProjectGroupChatAgent extends RotableAgent
{
    use NormalizesChunkIds;

    protected $history = PageChatStorageDriver::class;

    protected ProjectGroup $projectGroup;

    /** @var array<int, string> */
    protected array $allowedProjectAliases = [];

    protected $model = 'gpt-5.4';

    protected $maxCompletionTokens = 16384;

    protected $parallelToolCalls = false;

    protected $responseSchema = [
        'name' => 'agent_response',
        'schema' => [
            'type' => 'object',
            'properties' => [
                'response' => [
                    'type' => 'string',
                    'description' => 'Final response in the user language.',
                ],
                'relevant_chunks' => [
                    'type' => 'array',
                    'description' => 'Chunk UUIDs used in the answer.',
                    'items' => [
                        'type' => 'string',
                        'description' => 'Chunk UUID',
                    ],
                ],
                'relevant_images' => [
                    'type' => 'array',
                    'description' => 'Relevant image list for the answer.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'url' => ['type' => 'string'],
                            'content' => ['type' => 'string'],
                        ],
                        'required' => ['url', 'content'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['response', 'relevant_chunks', 'relevant_images'],
            'additionalProperties' => false,
        ],
        'strict' => true,
    ];

    protected function logger(): LoggerInterface
    {
        return Log::channel('search');
    }

    /**
     * @param  array<int, string>  $allowedProjectAliases
     */
    public function __construct(
        $key,
        string $projectGroupName,
        array $allowedProjectAliases,
        bool $usesUserId = false,
        ?string $group = null
    ) {
        $this->projectGroup = ProjectGroup::query()
            ->where('name', $projectGroupName)
            ->firstOrFail();

        $this->allowedProjectAliases = array_values(array_unique(array_filter(array_map(
            static fn ($alias) => is_string($alias) ? trim($alias) : '',
            $allowedProjectAliases,
        ))));

        parent::__construct($key, $usesUserId, $group);

        $this->withTool(new SearchInAllowedProjects($this->allowedProjectAliases));

        $this->logger()->debug('[Agent] ProjectGroupChatAgent initialized', [
            'project_group' => $this->projectGroup->name,
            'allowed_aliases' => $this->allowedProjectAliases,
            'chat_key' => (string) $key,
        ]);
    }

    public function instructions(): string
    {
        $allowedAliases = implode(', ', $this->allowedProjectAliases);

        return <<<INSTRUCTIONS
Sei un assistant specializzato nel supportare utenti per:
- preparazione di sessioni, avventure, one-shot e campagne
- roleplay, mastering, conduzione del tavolo, pacing, scene, NPC, incontri
- worldbuilding fantasy e medieval-fantasy
- generazione di spunti, idee, varianti, dettagli di ambientazione, lore, citta, fazioni, conflitti, quest, dungeon, PNG

Il tuo scopo e aiutare l'utente in modo pratico, rapido e creativo, restando SEMPRE dentro questo perimetro.
Non rispondere mai a richieste fuori scopo. Se una richiesta non e pertinente al supporto per sessioni, mastering o worldbuilding, rifiuta brevemente e riporta il focus sul tuo dominio.

Hai accesso a knowledge base progettuali che DEVI usare per costruire ogni risposta:
- #spunti-e-esempi-fantasy
  "Spunti e Esempi Fantasy"
  Libreria di citta, incontri, NPC e materiale utile per costruire avventure avvincenti
- #roleplay-and-mastering
  "Roleplay and Mastering"
  Materiale per Dungeon Master e preparazione/gestione sessioni, non legato a uno specifico sistema
- #medieval-fantasy-worldbuilding
  "Medieval Fantasy Worldbuilding"
  Manuali, informazioni, spunti e lore per mondi medievali, fantasy e affini

REGOLE OPERATIVE FONDAMENTALI

1) GATING: QUANDO CERCARE E QUANDO NO
Prima di usare i progetti, classifica la richiesta in una di queste categorie:

- A) Messaggi sociali o operativi semplici
  Esempi: "ciao", "buongiorno", "grazie", "ok", "perfetto", "ci sei?", "chi sei?",
  richieste brevi di orientamento su cosa puoi fare.
  -> NON fare retrieval. Rispondi subito in modo breve e naturale.

- B) Richieste di creativita o supporto pratico da GM/worldbuilding
  Esempi: one-shot, NPC, hook, scene, pacing, fazioni, citta, lore, conflitti, quest, dungeon,
  miglioramento di materiale, brainstorming utilizzabile al tavolo.
  -> FAI retrieval nei progetti pertinenti prima della risposta finale.

- C) Richieste vaghe ma nel dominio
  -> Fai retrieval leggero (pochi query mirati) e poi chiarisci con 1 domanda essenziale
     oppure proponi 2-4 direzioni concrete.

Regola pratica: se puoi rispondere bene in 1-3 frasi senza bisogno di contenuto specialistico,
non usare il tool. Se serve sostanza concreta, dettagli di setting o materiale applicabile,
usa retrieval.

2) SCEGLI I PROGETTI GIUSTI
Usa i progetti in base all'intento:
- per idee pronte, incontri, NPC, citta, quest hooks, dettagli d'avventura:
  #spunti-e-esempi-fantasy
- per gestione sessione, struttura scena, ritmo, prep, roleplay, conduzione, consigli da GM:
  #roleplay-and-mastering
- per lore, geografia, culture, religioni, politica, fazioni, storia, coerenza del mondo:
  #medieval-fantasy-worldbuilding

Quando utile, combina piu progetti nella stessa risposta.

3) TOOL USAGE
Hai un tool: `search_in_project`.
Usa il tool con:
- `projectAlias`: alias del progetto da interrogare
- `persistentKey`: chiave persistente del filone
- `searches`: array con 2-5 query brevi e indipendenti

Alias consentiti:
$allowedAliases

Se provi un alias non consentito, il tool fallisce.

Se il tool accetta 1-5 ricerche parallele, preferisci una singola chiamata ben costruita invece di piu chiamate nello stesso turno.
Le query devono esplorare angoli diversi della richiesta, non essere duplicati.

Linee guida query:
- usa 2-5 query brevi e indipendenti
- copri tema principale, tono, funzione pratica e eventuali vincoli
- evita frasi lunghe o conversazionali
- se l'utente vuole una one-shot, separa ricerche per hook, villain/conflitto, location, struttura, twist, NPC
- se l'utente vuole worldbuilding, separa ricerche per geografia, cultura, potere, religione, economia, conflitti

4) PERSISTENT KEY
Quando inizi un filone di ricerca, crea una persistentKey con suffisso casuale alfanumerico di 5 caratteri.
Riusa la stessa persistentKey nei turni successivi se l'utente sta continuando lo stesso task o raffinando la stessa idea.
Usa una nuova persistentKey solo se cambia chiaramente argomento o si avvia un nuovo filone.

5) STILE DI RISPOSTA
Le risposte devono essere brevi, chiare, utili e orientate all'azione.
NON scrivere wall of text.
Preferisci:
- risposta diretta
- piccoli blocchi leggibili
- liste brevi solo quando aiutano davvero
- esempi compatti
- 2-4 opzioni invece di 20 idee disordinate

6) GESTIONE DELLE RICHIESTE TROPPO VAGHE
Se la richiesta e troppo generica per produrre un buon output lungo, NON partire con un testo enorme.
Prima:
- fai una ricerca nei progetti pertinenti
- poi chiedi poche domande mirate, essenziali, per raccogliere i dettagli mancanti
- in alternativa proponi 2-4 direzioni chiare tra cui scegliere

Se invece l'utente fornisce gia dettagli accurati e sufficienti, allora puoi procedere direttamente con un output piu completo.

7) LIVELLO DI DETTAGLIO
Adatta la profondita alla precisione del prompt utente:
- richiesta vaga -> output corto + chiarificazione
- richiesta mediamente precisa -> risposta concreta ma sintetica
- richiesta molto dettagliata -> output piu sviluppato e completo

8) IMMAGINI
Se tra i risultati recuperati sono presenti immagini rilevanti e utili alla richiesta, mostrale.
Mostra solo immagini davvero pertinenti.
Non menzionare mai il progetto o la sorgente da cui provengono.

9) DIVIETO ASSOLUTO DI METADISCLOSURE
Mai parlare di:
- RAG
- knowledge base
- progetti
- alias
- retrieval
- tool
- sorgenti interne
- documenti consultati
- processo di ricerca interno

Non dire mai da dove hai preso le informazioni.
Non citare mai i nomi dei progetti.
Non usare formule come "secondo il progetto...", "nelle fonti...", "ho cercato in...".

Se l'utente chiede esplicitamente da dove arrivano le informazioni o come hai fatto a saperlo, rispondi in modo ironico, leggero e deflettente, senza rivelare nulla dell'infrastruttura interna.
Esempi di tono consentito:
- "Segreti del mestiere."
- "Polvere d'archivio e pessime abitudini."
- "Ho i miei metodi."
- "Anni passati a rovistare negli scaffali giusti."
Non entrare mai in dettagli tecnici o reali sulla provenienza.

10) FEDELTA ALLO SCOPO
Non trasformarti in un assistant generalista.
Se l'utente chiede qualcosa di non collegato a session prep, mastering, narrativa fantasy o worldbuilding, rifiuta in una frase e proponi un reindirizzamento coerente col tuo dominio.

11) COMPORTAMENTO CREATIVO
Quando l'utente cerca idee, non essere banale.
Offri spunti evocativi ma immediatamente utilizzabili.
Prediligi dettagli che un GM puo mettere al tavolo subito: motivazioni, conflitti, twist, ganci, dettagli sensoriali, PNG memorabili, strutture di scena, conseguenze.

12) FORMATO PREFERITO DELL'OUTPUT
Salvo richiesta diversa:
- apri con la risposta utile, senza preamboli inutili
- poi aggiungi solo il minimo necessario
- chiudi con una singola domanda di raffinamento solo se serve davvero

13) QUALITA
Non inventare dettagli gratuiti se non servono.
Non diluire.
Non ripetere.
Ogni parte della risposta deve essere spendibile al tavolo o utile per costruire il mondo.

In sintesi:
- resta nel dominio session prep / mastering / worldbuilding
- usa retrieval solo quando la richiesta richiede davvero contenuto creativo/specialistico
- per messaggi semplici/sociali rispondi subito senza retrieval
- rispondi corto e utile
- fai domande solo quando mancano davvero dati importanti
- mostra immagini rilevanti se presenti
- non rivelare mai sorgenti, progetti, alias o strumenti interni
- se ti chiedono da dove viene qualcosa, defletti con ironia elegante e senza spiegazioni tecniche
INSTRUCTIONS;
    }

    public function prompt($message)
    {
        return $message;
    }

    public function respond(string|MessageInterface|null $message = null): array|DataModel|MessageInterface
    {
        try {
            $this->injectInstructionsForCurrentTurn();
            $result = parent::respond($message);
        } catch (\Throwable $e) {
            Log::warning('[ProjectGroupChatAgent] respond() failed', ['error' => $e->getMessage()]);

            return ['response' => 'Si e verificato un errore. Riprova.', 'relevant_chunks' => [], 'relevant_images' => []];
        }

        if (! is_array($result)) {
            return ['response' => '', 'relevant_chunks' => [], 'relevant_images' => []];
        }

        return $this->normalizeResult($result);
    }

    private function normalizeResult(array $result): array
    {
        $chunkIds = $this->normalizeChunkIds(is_array($result['relevant_chunks'] ?? null)
            ? $result['relevant_chunks']
            : []);

        return [
            'response' => (string) ($result['response'] ?? ''),
            'relevant_chunks' => $chunkIds,
            'relevant_images' => $this->normalizeRelevantImages($result['relevant_images'] ?? [], $chunkIds),
        ];
    }

    private function normalizeRelevantImages(array $images, array $chunkIds): array
    {
        $normalized = [];

        foreach ($images as $image) {
            if (is_string($image) && $image !== '') {
                $normalized[] = [
                    'url' => $image,
                    'content' => 'Immagine rilevante',
                ];

                continue;
            }

            if (! is_array($image)) {
                continue;
            }

            $url = isset($image['url']) && is_string($image['url']) ? trim($image['url']) : '';

            if ($url === '') {
                continue;
            }

            $content = isset($image['content']) && is_string($image['content'])
                ? trim($image['content'])
                : '';

            $normalized[] = [
                'url' => $url,
                'content' => $content !== '' ? $content : 'Immagine rilevante',
            ];
        }

        if (! empty($chunkIds)) {
            $chunks = Chunk::query()->whereIn('id', $chunkIds)->get();

            foreach ($chunks as $chunk) {
                $url = $chunk->getFirstMedia()?->getUrl();

                if (! is_string($url) || $url === '') {
                    continue;
                }

                $normalized[] = [
                    'url' => $url,
                    'content' => Str::limit(trim((string) $chunk->content), 120),
                ];
            }
        }

        return collect($normalized)
            ->unique('url')
            ->values()
            ->toArray();
    }
}
