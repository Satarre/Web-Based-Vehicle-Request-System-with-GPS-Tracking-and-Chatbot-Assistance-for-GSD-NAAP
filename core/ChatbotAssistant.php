<?php
declare(strict_types=1);
/**
 * core/ChatbotAssistant.php — rule-based, role-scoped task assistance for the
 * personnel (role 6) GSD Assistant.
 *
 * Everything here is deterministic: hardcoded keyword maps for actions,
 * follow-up suggestions and page-aware topics, plus a few read-only queries
 * that are ALWAYS scoped to the authenticated account (created_by = $userId).
 * No external AI service, no writes, no client-supplied account ids — a user
 * can only ever see rows they own.
 *
 * api/chatbot.php delegates here only AFTER ChatbotMatcher picked a match, and
 * only when the matched keyword is one of the synthetic dynamic intents
 * (see intentFaqs()). Every stored FAQ keeps its byte-for-byte old behavior.
 */

final class ChatbotAssistant
{
    /** Consecutive unanswered asks before the panel says "contact the GSD". */
    public const ESCALATE_AFTER = 3;

    /** Misses older than this are not chained into the same streak. */
    public const ESCALATE_WINDOW_HOURS = 24;

    /** Tickets listed by the status lookup (newest first). */
    public const MAX_LISTED = 3;

    /** In-app destinations. hrefs are relative; api/chatbot.php + chatbot.js
     *  both refuse anything that is not a safe relative path. */
    private const ROUTES = [
        'new_request' => ['Open request form', 'personnel/request_form.php'],
        'my_requests' => ['Open My Requests', 'personnel/requests.php'],
        'feedback'    => ['Open Feedback',    'personnel/feedback.php'],
    ];

    /** Follow-up chips per matched keyword (#5 contextual follow-ups). */
    private const NEXT_TOPICS = [
        'create trip request'   => ['guided request', 'required fields', 'attach request letter'],
        'required fields'       => ['create trip request', 'attach request letter'],
        'attach request letter' => ['required fields', 'schedule rules'],
        'schedule rules'        => ['create trip request', 'after approval'],
        'status meaning'        => ['my request status', 'after approval', 'cancel request'],
        'after approval'        => ['my request status', 'trip details', 'status meaning'],
        'cancel request'        => ['my request status', 'status meaning'],
        'reschedule trip'       => ['my request status', 'status meaning'],
        'view ticket'           => ['my request status', 'trip details'],
        'submit feedback'       => ['view ticket', 'contact gsd'],
        'passenger manifest'    => ['create trip request', 'required fields'],
        'contact gsd'           => ['create trip request', 'my request status'],
        'my request status'     => ['trip details', 'status meaning', 'cancel request'],
        'trip details'          => ['my request status', 'status meaning'],
    ];

    /** Link buttons attached to a matched keyword (#2 new-trip assistance,
     *  plus the next-step links used by the status handlers). */
    private const KEYWORD_ACTIONS = [
        'create trip request' => ['new_request'],
        'cancel request'      => ['my_requests'],
        'reschedule trip'     => ['my_requests'],
        'view ticket'         => ['my_requests'],
        'submit feedback'     => ['feedback'],
        'my request status'   => ['my_requests'],
        'trip details'        => ['my_requests'],
    ];

    /** Page-aware topic chips (#6), keyed by the normalized path. */
    private const PAGE_TOPICS = [
        'personnel/requests.php'     => ['guided request', 'my request status', 'status meaning', 'cancel request', 'reschedule trip'],
        'personnel/history.php'      => ['my request status', 'view ticket', 'submit feedback'],
        'personnel/feedback.php'     => ['submit feedback', 'view ticket', 'contact gsd'],
        'personnel/request_form.php' => ['required fields', 'attach request letter', 'schedule rules', 'guided request'],
        'ticket.php'                 => ['trip details', 'status meaning', 'my request status'],
    ];

    /** Page-aware link actions (#6). */
    private const PAGE_ACTIONS = [
        'personnel/requests.php' => ['new_request'],
        'personnel/history.php'  => ['new_request'],
        'ticket.php'             => ['my_requests'],
    ];

    /* ────────────────────────────────────────── grouped topic browser (UI) */

    /**
     * Display groups for the topic browser, in render order. Keys are the
     * canonical FAQ/intent keywords; anything not listed here (e.g. a FAQ an
     * admin adds later) lands in the "Other topics" group, so a topic can
     * never silently disappear from the UI.
     */
    private const TOPIC_GROUPS = [
        'Requests'          => ['guided request', 'create trip request', 'required fields',
                                'attach request letter', 'schedule rules', 'passenger manifest'],
        'Status & tracking' => ['my request status', 'status meaning', 'trip details', 'after approval'],
        'Changes & help'    => ['cancel request', 'reschedule trip', 'view ticket',
                                'submit feedback', 'contact gsd'],
    ];

    /** Link buttons shown at the top of a group (form shortcuts). */
    private const GROUP_ACTIONS = [
        'Requests' => ['new_request'],
    ];

    /**
     * Friendly chip labels. DISPLAY ONLY — `value` always stays the canonical
     * keyword, so ChatbotMatcher, the stored FAQ text and the conversation log
     * are unaffected. Unmapped keywords fall back to their own text.
     */
    private const TOPIC_LABELS = [
        'guided request'        => 'Fill it in with me',
        'create trip request'   => 'New trip request',
        'required fields'       => 'What to fill in',
        'attach request letter' => 'Upload request letter',
        'schedule rules'        => 'Schedule rules',
        'passenger manifest'    => 'Passengers',
        'my request status'     => 'Check my request status',
        'status meaning'        => 'What statuses mean',
        'trip details'          => 'Trip details',
        'after approval'        => 'After approval',
        'cancel request'        => 'Cancel a request',
        'reschedule trip'       => 'Reschedule a trip',
        'view ticket'           => 'View a ticket',
        'submit feedback'       => 'Submit feedback',
        'contact gsd'           => 'Contact the GSD',
    ];

    /* ────────────────────────────────────────────────────────────── intents */

    /**
     * Synthetic FAQ rows for the dynamic intents. They are never stored in the
     * `chatbot` table — they exist only for one ChatbotMatcher pass, so admins
     * keep full control of the stored FAQ content. Synonyms deliberately avoid
     * every phrase already used by the 12 seeded FAQs (legacy precedence), so
     * an existing keyword can never be stolen by a new intent.
     *
     * @return array<int, array{prompt_keyword: string, response_text: string, keywords: string, __intent: string}>
     */
    public static function intentFaqs(): array
    {
        return [
            [
                'prompt_keyword' => 'guided request',
                'response_text'  => '',
                // Natural request phrasings. Each is an exact phrase so it wins
                // the matcher's longer-phrase tie-break against the greedy short
                // synonyms of other entries (e.g. "my trip", "file a trip").
                'keywords'       => 'help me create a trip ticket|create trip ticket|new trip ticket'
                    . '|start a trip request|start a trip ticket|help me request a trip ticket'
                    . '|help me request a trip|request a trip ticket|request a trip|trip ticket request'
                    . '|help me fill my request|fill my request|fill out a trip ticket'
                    . '|help me make a trip ticket|make a trip ticket|book a trip ticket'
                    . '|file a trip ticket|i need a trip ticket|i want to request a trip ticket'
                    . '|walk me through it|fill out a request|guide me',
                '__intent'       => 'guided_request',
            ],
            [
                'prompt_keyword' => 'my request status',
                'response_text'  => '',
                'keywords'       => 'status|my status|request status|my requests|check my status|status of my request',
                '__intent'       => 'status_lookup',
            ],
            [
                'prompt_keyword' => 'trip details',
                'response_text'  => '',
                'keywords'       => 'details|trip info|my trip|assigned vehicle|assigned driver|my driver|my vehicle',
                '__intent'       => 'trip_details',
            ],
        ];
    }

    /**
     * Execute a dynamic intent for the authenticated user.
     *
     * @param array $u Auth::requireApi() user (id, role_id, full_name, …)
     * @return array{answer: string, cards: array, actions: array, suggestions: array, kind: string, label: string}
     */
    public static function run(string $intent, PDO $pdo, array $u, string $question): array
    {
        return match ($intent) {
            'status_lookup'  => self::statusLookup($pdo, $u) + ['kind' => 'live', 'label' => 'Your trips'],
            'trip_details'   => self::tripDetails($pdo, $u, $question) + ['kind' => 'live', 'label' => 'Trip details'],
            'guided_request' => ['answer' => '', 'cards' => [], 'actions' => [], 'suggestions' => [],
                                 'kind' => 'wizard', 'label' => 'New trip request',
                                 'steps' => self::wizardSteps()],
            default          => ['answer' => '', 'cards' => [], 'actions' => [], 'suggestions' => [],
                                 'kind' => 'faq', 'label' => ''],
        };
    }

    /**
     * The guided trip-request wizard, step by step. Field names match exactly
     * what api/trips.php `save_draft` accepts, so the panel only has to collect
     * values and hand them back — every rule (ownership, completeness, manifest
     * counts, the signed letter) stays enforced by the existing API.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function wizardSteps(): array
    {
        return [
            [
                'id'     => 'who',
                'title'  => "Who's travelling?",
                'hint'   => 'The passenger named on the ticket.',
                'fields' => [
                    ['name' => 'passenger_name', 'label' => 'First name *', 'type' => 'text',
                     'required' => true, 'maxlength' => 150, 'cap' => true, 'placeholder' => 'First Name'],
                    ['name' => 'surname', 'label' => 'Surname', 'type' => 'text',
                     'required' => false, 'maxlength' => 100, 'half' => true, 'cap' => true,
                     'placeholder' => 'Surname'],
                    ['name' => 'suffix', 'label' => 'Suffix', 'type' => 'text',
                     'required' => false, 'maxlength' => 10, 'half' => true, 'cap' => true,
                     'placeholder' => 'Jr., Sr., III'],
                    ['name' => 'middle_initial', 'label' => 'M.I.', 'type' => 'text',
                     'required' => false, 'maxlength' => 3, 'half' => true, 'cap' => true,
                     'placeholder' => 'M.'],
                    ['name' => 'middle_name', 'label' => 'Middle name', 'type' => 'text',
                     'required' => false, 'maxlength' => 100, 'half' => true, 'cap' => true,
                     'placeholder' => 'REYES'],
                ],
            ],
            [
                'id'     => 'requester',
                'title'  => 'Your details',
                'hint'   => 'Used for the ticket and the approval e-mail.',
                'fields' => [
                    ['name' => 'id_number', 'label' => 'ID number *', 'type' => 'text',
                     'required' => true, 'maxlength' => 50, 'cap' => true, 'placeholder' => 'Employee ID'],
                    ['name' => 'requester_type', 'label' => 'Requester type *', 'type' => 'select',
                     'required' => true, 'options' => ['Personnel', 'Student']],
                    ['name' => 'email', 'label' => 'Email *', 'type' => 'email',
                     'required' => true, 'maxlength' => 150, 'placeholder' => 'name@email.com'],
                ],
            ],
            [
                'id'     => 'trip',
                'title'  => 'Where to, and why?',
                'hint'   => 'Pick a destination suggestion so the route can be drawn.',
                'fields' => [
                    ['name' => 'place_to_visit', 'label' => 'Destination *', 'type' => 'place',
                     'required' => true, 'maxlength' => 255, 'placeholder' => 'Search for a place'],
                    ['name' => 'purpose', 'label' => 'Purpose of travel *', 'type' => 'textarea',
                     'required' => true, 'maxlength' => 500,
                     'placeholder' => 'State the official purpose of the trip'],
                ],
                'origin' => [
                    'lat'  => Settings::getFloat('NAAP_ORIGIN_LAT'),
                    'lng'  => Settings::getFloat('NAAP_ORIGIN_LNG'),
                    'name' => (string) Settings::get('NAAP_ORIGIN_NAME', ''),
                ],
            ],
            [
                'id'     => 'schedule',
                'title'  => 'Departure and return',
                'hint'   => 'Departure must be in the future; return after departure.',
                'fields' => [
                    ['name' => 'scheduled_departure', 'label' => 'Scheduled departure *',
                     'type' => 'datetime-local', 'required' => true, 'placeholder' => 'Select date & time'],
                    ['name' => 'scheduled_return', 'label' => 'Scheduled return *',
                     'type' => 'datetime-local', 'required' => true, 'placeholder' => 'Select date & time'],
                ],
            ],
            [
                'id'     => 'passengers',
                'title'  => 'Passenger manifest',
                'hint'   => 'Name every passenger. The guard checks this at the gate.',
                'fields' => [
                    ['name' => 'number_of_passengers', 'label' => 'Number of passengers *',
                     'type' => 'number', 'required' => true, 'min' => 1, 'max' => 999, 'placeholder' => '1'],
                ],
                'manifest'    => true,
                'add_self'    => true,
                'max'         => 999,
            ],
            [
                'id'     => 'letter',
                'title'  => 'Signed request letter',
                'hint'   => 'PDF, PNG, JPG or Word, up to 20 MB. Required before submitting.',
                'fields' => [
                    ['name' => 'request_letter', 'label' => 'Request letter *', 'type' => 'file',
                     'required' => true, 'accept' => '.pdf,.png,.jpg,.jpeg,.doc,.docx'],
                ],
            ],
            [
                'id'     => 'review',
                'title'  => 'Review & submit',
                'hint'   => 'Check everything, then send it to the SAO for approval.',
                'fields' => [],
            ],
        ];
    }

    /* ───────────────────────────────────────────────────────── handlers */

    /** #3 Request Status Lookup — the caller's own tickets + drafts. */
    private static function statusLookup(PDO $pdo, array $u): array
    {
        $uid = (int) $u['id'];

        $st = $pdo->prepare(
            'SELECT t.ticket_id, t.dtt_number, t.status_id, t.created_by, t.place_to_visit,
                    t.scheduled_departure, t.scheduled_return, t.reason,
                    t.driver_id, t.vehicle_id, s.status_name
             FROM trip_tickets t
             JOIN request_status s ON s.status_id = t.status_id
             WHERE t.created_by = ? AND t.is_active = 1
             ORDER BY t.submitted_at DESC, t.ticket_id DESC
             LIMIT ' . (self::MAX_LISTED + 1)
        );
        $st->execute([$uid]);
        $rows = $st->fetchAll();
        $more = count($rows) > self::MAX_LISTED;

        $ds = $pdo->prepare(
            'SELECT draft_id, place_to_visit, scheduled_departure, scheduled_return
             FROM trip_drafts WHERE created_by = ? ORDER BY draft_id DESC LIMIT 2'
        );
        $ds->execute([$uid]);
        $drafts = $ds->fetchAll();

        if ($rows === [] && $drafts === []) {
            return [
                'answer' => "You have no trip requests yet. You can start one from My Requests:\n"
                    . "open the form, fill in the details, attach your request letter, and submit it for approval.",
                'cards'       => [],
                'actions'     => self::actionsFor('create trip request'),
                'suggestions' => self::suggestionsFor('my request status'),
            ];
        }

        $lines   = [];
        $actions = [];
        $cards   = [];

        foreach (array_slice($rows, 0, self::MAX_LISTED) as $t) {
            $lines[] = self::ticketLine($t, $uid);
            $cards[] = self::statusCard($t, $uid);
        }
        foreach ($drafts as $d) {
            $place = trim((string) $d['place_to_visit']);
            $line  = 'Draft request' . ($place !== '' ? ' for ' . $place : '');
            $when  = self::dateRange($d['scheduled_departure'] ?? null, $d['scheduled_return'] ?? null);
            if ($when !== '') {
                $line .= "\n" . $when;
            }
            $lines[] = $line . "\nNot submitted yet. Open it from My Requests to finish it.";
            $cards[] = [
                'dtt'        => 'Draft request',
                'status'     => 'DRAFT',
                'status_key' => 'draft',
                'schedule'   => $when,
                'place'      => $place,
                'guidance'   => 'Not submitted yet. Open it from My Requests to finish it.',
                'href'       => 'personnel/request_form.php?id=' . (int) $d['draft_id'],
                'is_draft'   => true,
            ];
        }
        if ($more) {
            $lines[] = 'Showing your latest ' . self::MAX_LISTED . ' requests.';
        }

        $actions[] = self::route('my_requests');

        return [
            // The text stays for the conversation log; the panel renders the cards.
            'answer'      => "Here are your latest trip requests:\n\n" . implode("\n\n", $lines),
            'cards'       => $cards,
            'actions'     => $actions,
            'suggestions' => self::suggestionsFor('my request status'),
        ];
    }

    /** #4 Trip Details Lookup — driver, vehicle, schedule for one owned ticket. */
    private static function tripDetails(PDO $pdo, array $u, string $question): array
    {
        $uid = (int) $u['id'];

        $dtt = self::ownedDttIn($pdo, $uid, $question);
        $t   = self::fetchTrip($pdo, $uid, $dtt);

        if ($t === null) {
            return [
                'answer' => $dtt !== null
                    ? "I couldn't find a request with that number among your tickets.\nOpen My Requests to see your own tickets."
                    : "You don't have any trip tickets yet.\nYou can create one from My Requests.",
                'cards'       => [],
                'actions'     => $dtt !== null ? self::actionsFor('trip details') : self::actionsFor('create trip request'),
                'suggestions' => self::suggestionsFor('trip details'),
            ];
        }

        $lines = [(string) ($t['dtt_number'] ?: '#' . (int) $t['ticket_id']) . ': ' . (string) $t['status_name']];

        $place = trim((string) $t['place_to_visit']);
        if ($place !== '') {
            $lines[] = 'Destination: ' . $place;
        }
        $when = self::dateRange($t['scheduled_departure'] ?? null, $t['scheduled_return'] ?? null);
        if ($when !== '') {
            $lines[] = $when;
        }

        $vehicle = trim((string) ($t['plate_number'] ?? ''));
        if ($vehicle !== '') {
            $model = trim((string) ($t['brand'] ?? '') . ' ' . (string) ($t['model_name'] ?? ''));
            $lines[] = 'Vehicle: ' . $vehicle . ($model !== '' ? ' (' . $model . ')' : '');
        }
        $driver = trim((string) ($t['driver_name'] ?? ''));
        if ($driver !== '') {
            $lines[] = 'Driver: ' . $driver;
        }
        if ($vehicle === '' && $driver === '') {
            $lines[] = 'No vehicle or driver has been assigned yet.';
        }

        $guidance = self::guidance((int) $t['status_id'], $t, $uid);
        if ($guidance !== '') {
            $lines[] = $guidance;
        }

        return [
            // The text stays for the conversation log; the panel renders the card.
            'answer'      => implode("\n", $lines),
            'cards'       => [self::detailCard($t, $uid)],
            'actions'     => self::actionsFor('trip details'),
            'suggestions' => self::suggestionsFor('trip details'),
        ];
    }

    /**
     * One ticket as a chat card (pure — takes a row, returns display data).
     * `status_key` is the app-wide badge mapping (status_name → badge-{key}).
     */
    public static function statusCard(array $t, int $userId): array
    {
        $status = (string) ($t['status_name'] ?? TripState::name((int) ($t['status_id'] ?? 0)));
        return [
            'dtt'        => (string) ($t['dtt_number'] ?: '#' . (int) ($t['ticket_id'] ?? 0)),
            'status'     => $status,
            'status_key' => self::badgeKey($status),
            'schedule'   => self::cardSchedule($t['scheduled_departure'] ?? null, $t['scheduled_return'] ?? null),
            'place'      => trim((string) ($t['place_to_visit'] ?? '')),
            'guidance'   => self::guidance((int) ($t['status_id'] ?? 0), $t, $userId),
            'href'       => 'ticket.php?id=' . (int) ($t['ticket_id'] ?? 0),
            'is_draft'   => false,
        ];
    }

    /** The detail card adds the assignment pair (vehicle / driver). */
    public static function detailCard(array $t, int $userId): array
    {
        $card    = self::statusCard($t, $userId);
        $vehicle = trim((string) ($t['plate_number'] ?? ''));
        if ($vehicle !== '') {
            $model = trim((string) ($t['brand'] ?? '') . ' ' . (string) ($t['model_name'] ?? ''));
            $vehicle .= $model !== '' ? ' (' . $model . ')' : '';
        }
        $card['vehicle'] = $vehicle;
        $card['driver']  = trim((string) ($t['driver_name'] ?? ''));
        return $card;
    }

    /** Status name → the app's badge class key (see AGENTS.md). */
    private static function badgeKey(string $status): string
    {
        return strtolower(str_replace('_', '-', $status));
    }

    /** Card schedule line: "D → R" without the "Schedule:" label prefix. */
    private static function cardSchedule(?string $dep, ?string $ret): string
    {
        $d = self::fmt($dep);
        $r = self::fmt($ret);
        if ($d === '' && $r === '') {
            return '';
        }
        if ($d === '') {
            return 'Return: ' . $r;
        }
        if ($r === '') {
            return 'Departure: ' . $d;
        }
        return $d . ' → ' . $r;
    }

    /* ────────────────────────────────────────────── scoped data access */

    /** The caller's own ticket, by number when given, else the most actionable. */
    private static function fetchTrip(PDO $pdo, int $uid, ?string $dtt): ?array
    {
        $sql = 'SELECT t.ticket_id, t.dtt_number, t.status_id, t.created_by, t.place_to_visit, t.reason,
                       t.scheduled_departure, t.scheduled_return, t.driver_id, t.vehicle_id,
                       s.status_name, d.full_name AS driver_name,
                       v.plate_number, v.model_name, v.brand
                FROM trip_tickets t
                JOIN request_status s ON s.status_id = t.status_id
                LEFT JOIN account d ON d.account_id = t.driver_id
                LEFT JOIN vehicle v ON v.vehicle_id = t.vehicle_id
                WHERE t.created_by = ? AND t.is_active = 1';
        $params = [$uid];
        if ($dtt !== null) {
            $sql .= ' AND t.dtt_number = ?';
            $params[] = $dtt;
        }
        // Ongoing first, then dispatched, rescheduled, approved, pending, done.
        $sql .= ' ORDER BY FIELD(t.status_id, 6, 5, 10, 4, 2, 7, 8, 9), t.submitted_at DESC, t.ticket_id DESC LIMIT 1';

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** Match a ticket number typed in the question against the caller's own. */
    private static function ownedDttIn(PDO $pdo, int $uid, string $question): ?string
    {
        $st = $pdo->prepare(
            'SELECT dtt_number FROM trip_tickets WHERE created_by = ? AND is_active = 1 AND dtt_number IS NOT NULL'
        );
        $st->execute([$uid]);
        $q = ChatbotMatcher::norm($question);
        if ($q === '') {
            return null;
        }
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $dtt) {
            $n = ChatbotMatcher::norm((string) $dtt);
            if ($n !== '' && str_contains($q, $n)) {
                return (string) $dtt;
            }
        }
        return null;
    }

    /**
     * #8 Status-Based Assistance — what the status means + only the next steps
     * the state machine actually allows for this owner (TripState::can is the
     * single authority, so the bot can never suggest a forbidden action).
     */
    public static function guidance(int $statusId, array $ticket, int $userId): string
    {
        if ($statusId === TripState::DRAFT) {
            return 'Not submitted yet. Open My Requests to finish and submit it.';
        }

        $cancel = TripState::can($ticket, TripState::CANCELLED, [
            'user_id' => $userId,
            'role_id' => 6,
            'has_reason' => true,
        ]) === true ? ' You can still cancel this request.' : '';

        $reason = trim((string) ($ticket['reason'] ?? ''));
        $why    = $reason !== '' ? ' Reason: ' . $reason : '';

        return match ($statusId) {
            TripState::PENDING     => 'Waiting for the SAO to approve it.' . $cancel,
            TripState::APPROVED    => 'Approved. Waiting for the GSD Dispatcher to assign a vehicle and driver.' . $cancel,
            TripState::DISPATCHED  => 'A vehicle and driver are assigned.' . $cancel,
            TripState::ON_GOING    => 'The trip is in progress right now.',
            TripState::COMPLETED   => 'The trip is finished. You can submit feedback from the Feedback page.',
            TripState::CANCELLED   => 'This request was cancelled.' . $why,
            TripState::REJECTED    => 'This request was rejected.' . $why,
            TripState::RESCHEDULED => 'The GSD Dispatcher sent this back for a new schedule. '
                . 'Open My Requests and click Edit Schedule.',
            default => '',
        };
    }

    /* ───────────────────────────────────────────── maps / formatting */

    /** Link actions for a matched keyword, e.g. the new-request form button. */
    public static function actionsFor(string $keyword): array
    {
        $k   = ChatbotMatcher::norm($keyword);
        $out = [];
        foreach (self::KEYWORD_ACTIONS[$k] ?? [] as $routeKey) {
            $out[] = self::route($routeKey);
        }
        return $out;
    }

    /** Follow-up chips for a matched keyword (capped at three). */
    public static function suggestionsFor(string $keyword): array
    {
        $k   = ChatbotMatcher::norm($keyword);
        $out = [];
        foreach (self::NEXT_TOPICS[$k] ?? [] as $topic) {
            if ($topic !== $k) {
                $out[] = self::ask(self::topicLabel($topic), $topic);
            }
        }
        return array_slice($out, 0, 3);
    }

    /** Page-aware topic chips for the page being viewed (allowlisted). */
    public static function pageTopics(string $path): array
    {
        return self::PAGE_TOPICS[self::normalizePath($path)] ?? [];
    }

    /** Page-aware link actions for the page being viewed (allowlisted). */
    public static function pageActions(string $path): array
    {
        $out = [];
        foreach (self::PAGE_ACTIONS[self::normalizePath($path)] ?? [] as $routeKey) {
            $out[] = self::route($routeKey);
        }
        return $out;
    }

    /* ────────────────────────────────────────── grouped topic browser (UI) */

    /** Display label for a topic (falls back to the canonical keyword). */
    public static function topicLabel(string $keyword): string
    {
        return self::TOPIC_LABELS[ChatbotMatcher::norm($keyword)] ?? trim($keyword);
    }

    /**
     * Grouped topics for the browse view.
     *
     * @param array $rows FAQ rows + intent rows (prompt_keyword/keywords)
     * @return array<int, array{title: string, topics: array, actions?: array}>
     */
    public static function topicGroups(array $rows): array
    {
        $entries = [];
        foreach ($rows as $row) {
            $kw = trim((string) ($row['prompt_keyword'] ?? ''));
            if ($kw !== '') {
                $entries[ChatbotMatcher::norm($kw)] = self::topicEntry($row);
            }
        }

        $groups = [];
        $seen   = [];
        foreach (self::TOPIC_GROUPS as $title => $keywords) {
            $topics = [];
            foreach ($keywords as $kw) {
                $key = ChatbotMatcher::norm($kw);
                if (isset($entries[$key]) && !isset($seen[$key])) {
                    $topics[] = $entries[$key];
                    $seen[$key] = true;
                }
            }
            if ($topics !== []) {
                $group = ['title' => $title, 'topics' => $topics];
                $actions = self::groupActions($title);
                if ($actions !== []) {
                    $group['actions'] = $actions;
                }
                $groups[] = $group;
            }
        }

        // Anything an admin added (or renamed) stays reachable instead of
        // silently vanishing from the browser.
        $other = [];
        foreach ($entries as $key => $entry) {
            if (!isset($seen[$key])) {
                $other[] = $entry;
            }
        }
        if ($other !== []) {
            $groups[] = ['title' => 'Other topics', 'topics' => $other];
        }
        return $groups;
    }

    /**
     * The "For this page" group, or null when the page has no page-aware topics.
     *
     * @return array{title: string, topics: array}|null
     */
    public static function pageGroup(string $path, array $rows): ?array
    {
        $keywords = self::pageTopics($path);
        if ($keywords === []) {
            return null;
        }
        $byNorm = [];
        foreach ($rows as $row) {
            $kw = trim((string) ($row['prompt_keyword'] ?? ''));
            if ($kw !== '') {
                $byNorm[ChatbotMatcher::norm($kw)] = self::topicEntry($row);
            }
        }
        $topics = [];
        foreach ($keywords as $kw) {
            $entry = $byNorm[ChatbotMatcher::norm($kw)] ?? null;
            if ($entry !== null) {
                $topics[] = $entry;
            }
        }
        return $topics !== [] ? ['title' => 'For this page', 'topics' => $topics] : null;
    }

    /** One browse entry: display label + canonical value + filter text. */
    private static function topicEntry(array $row): array
    {
        $kw    = trim((string) ($row['prompt_keyword'] ?? ''));
        $search = [self::topicLabel($kw), $kw];
        foreach (preg_split('/[|\r\n]+/', (string) ($row['keywords'] ?? '')) as $synonym) {
            $synonym = trim($synonym);
            if ($synonym !== '') {
                $search[] = $synonym;
            }
        }
        return [
            'label'  => self::topicLabel($kw),
            'value'  => $kw,
            'search' => array_values(array_unique($search)),
        ];
    }

    /** Group shortcut buttons (e.g. the request form inside "Requests"). */
    private static function groupActions(string $title): array
    {
        $out = [];
        foreach (self::GROUP_ACTIONS[$title] ?? [] as $routeKey) {
            $out[] = self::route($routeKey);
        }
        return $out;
    }

    /**
     * Reduce a client-supplied path (which includes BASE_URL) to the app-relative
     * form used by the page maps. Unknown paths simply match nothing.
     */
    public static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        $p = parse_url($path, PHP_URL_PATH);
        if (!is_string($p) || $p === '') {
            return '';
        }
        $p    = str_replace('\\', '/', $p);
        $base = parse_url(BASE_URL, PHP_URL_PATH);
        $base = '/' . trim(is_string($base) ? $base : '', '/');
        $base = $base === '/' ? '' : $base . '/';
        if ($base !== '' && str_starts_with($p, $base)) {
            $p = substr($p, strlen($base));
        }
        return ltrim($p, '/');
    }

    /* ──────────────────────────────────────────────── escalation (#13) */

    /**
     * How many of the caller's most recent asks, in a row, went unanswered.
     * Read from the append-only chat log; scoped to one account.
     */
    public static function consecutiveMisses(PDO $pdo, int $accountId, int $windowHours = self::ESCALATE_WINDOW_HOURS): int
    {
        $since = date('Y-m-d H:i:s', time() - max(1, $windowHours) * 3600);
        $st = $pdo->prepare(
            'SELECT keyword FROM chat_conversations
             WHERE account_id = ? AND created_at >= ?
             ORDER BY conversation_id DESC LIMIT 10'
        );
        $st->execute([$accountId, $since]);

        $streak = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $keyword) {
            if ($keyword !== null && (string) $keyword !== '') {
                break;
            }
            $streak++;
        }
        return $streak;
    }

    /**
     * The escalation bubble: the fixed sentence plus the body of the existing
     * (admin-editable) `contact gsd` FAQ. The FAQ lookup reuses the rows
     * api/chatbot.php already loaded — no extra query. If that FAQ is hidden
     * or inactive the detail silently falls back to an empty string.
     *
     * @return array{message: string, detail: string}
     */
    public static function escalation(array $faqs): array
    {
        $message = 'Please contact the GSD for assistance regarding your query.';
        $detail  = '';
        foreach ($faqs as $faq) {
            if (ChatbotMatcher::norm((string) ($faq['prompt_keyword'] ?? '')) === 'contact gsd') {
                $detail = trim((string) ($faq['response_text'] ?? ''));
                break;
            }
        }
        return ['message' => $message, 'detail' => $detail];
    }

    /** Buttons attached to the escalation card (in-app links only). */
    public static function escalationActions(): array
    {
        return [self::route('my_requests')];
    }

    /* ─────────────────────────────────────────────────────── helpers */

    private static function ticketLine(array $t, int $userId): string
    {
        $line = (string) ($t['dtt_number'] ?: '#' . (int) $t['ticket_id']) . ': ' . (string) $t['status_name'];
        $when = self::dateRange($t['scheduled_departure'] ?? null, $t['scheduled_return'] ?? null);
        if ($when !== '') {
            $line .= "\n" . $when;
        }
        $guidance = self::guidance((int) $t['status_id'], $t, $userId);
        if ($guidance !== '') {
            $line .= "\n" . $guidance;
        }
        return $line;
    }

    private static function fmt(?string $dt): string
    {
        if ($dt === null || trim($dt) === '') {
            return '';
        }
        $ts = strtotime($dt);
        return $ts !== false ? date('D, M j, g:i A', $ts) : '';
    }

    private static function dateRange(?string $dep, ?string $ret): string
    {
        $d = self::fmt($dep);
        $r = self::fmt($ret);
        if ($d === '' && $r === '') {
            return '';
        }
        if ($d === '') {
            return 'Return: ' . $r;
        }
        if ($r === '') {
            return 'Departure: ' . $d;
        }
        return 'Schedule: ' . $d . ' → ' . $r;
    }

    private static function link(string $label, string $href): array
    {
        return ['type' => 'link', 'label' => $label, 'href' => $href];
    }

    private static function ask(string $label, string $value): array
    {
        return ['type' => 'ask', 'label' => $label, 'value' => $value];
    }

    private static function route(string $routeKey): array
    {
        [$label, $href] = self::ROUTES[$routeKey];
        return self::link($label, $href);
    }
}
