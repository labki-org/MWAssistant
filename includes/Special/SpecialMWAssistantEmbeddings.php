<?php

namespace MWAssistant\Special;

use MediaWiki\Exception\PermissionsError;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\Widget\TitleInputWidget;
use MWAssistant\Jobs\DeleteEmbeddingJob;
use MWAssistant\Jobs\UpdateEmbeddingJob;
use MWAssistant\MCP\EmbeddingsClient;
use MWAssistant\NamespacePermissions;

/**
 * Special page that shows embedding statistics and allows updating vectors
 * for either a single page or an entire namespace.
 *
 * Responsibilities:
 *  - Display embedding index statistics from the MCP server
 *  - Trigger batch updates for an entire namespace
 *  - Trigger single-page embedding updates
 *  - Provide autocomplete input for page titles
 *
 * Viewing requires 'mwassistant-use' and only covers namespaces the user
 * can read. Queuing updates (POST) requires 'mwassistant-manage-embeddings'
 * and a valid edit token.
 */
class SpecialMWAssistantEmbeddings extends SpecialPage
{

    /** @var EmbeddingsClient */
    private $client;

    /** @var MediaWikiServices */
    private $services;

    /** Right required to queue embedding updates. */
    private const MANAGE_RIGHT = 'mwassistant-manage-embeddings';

    /** Per-request cache for the MCP stats payload — kept so submit-then-render
     *  doesn't issue two round-trips to MCP. */
    private ?array $cachedStats = null;

    public function __construct()
    {
        parent::__construct('MWAssistantEmbeddings', 'mwassistant-use');
        $this->client = new EmbeddingsClient();
        $this->services = MediaWikiServices::getInstance();
    }

    /**
     * Fetch (and memoize) MCP embedding stats for this request.
     */
    private function getStatsCached(): array
    {
        if ($this->cachedStats === null) {
            $this->cachedStats = $this->client->getStats($this->getUser());
        }
        return $this->cachedStats;
    }

    /**
     * Entry point for the special page.
     */
    public function execute($subPage)
    {
        $this->checkPermissions();

        $this->setHeaders();

        $request = $this->getRequest();
        $output = $this->getOutput();
        $output->setPageTitle($this->msg('mwassistantembeddings-pagetitle')->text());

        // Widgets + RL modules
        $output->addModules([
            'ext.mwassistant.embeddings',
            'mediawiki.widgets',
        ]);
        $output->enableOOUI();

        $isBatch = $request->wasPosted() && $request->getCheck('batch_update');
        $isSingle = $request->wasPosted() && $request->getText('page') !== '';
        if (($isBatch || $isSingle) && !$this->canManage()) {
            throw new PermissionsError(self::MANAGE_RIGHT);
        }

        $output->addHTML('<div class="mwassistant-dashboard">');

        if (($isBatch || $isSingle)
            && !$this->getContext()->getCsrfTokenSet()->matchTokenField('wpEditToken')
        ) {
            $output->addHTML(Html::errorBox($this->msg('sessionfailure')->parse()));
        } else {
            // ----- Handle Batch Update -----
            if ($isBatch) {
                $ns = (int) $request->getInt('namespace_selector');
                $this->handleBatchUpdate($ns);
            }

            // ----- Handle Manual Update -----
            if ($isSingle) {
                $page = $request->getText('page');
                $this->handleSingleUpdate($page);
            }
        }

        // ----- Render main stats table -----
        $this->renderStatsTable();

        $output->addHTML('</div>');
    }

    /**
     * Whether the current user may queue embedding updates.
     */
    private function canManage(): bool
    {
        return $this->getUser()->isAllowed(self::MANAGE_RIGHT);
    }

    /**
     * Hidden CSRF token field for the POST forms.
     */
    private function getTokenField(): string
    {
        return Html::hidden('wpEditToken', $this->getContext()->getCsrfTokenSet()->getToken()->toString());
    }

    /**
     * Namespaces shown on this page: non-talk, non-User content namespaces
     * the current user can read, as id => display name.
     *
     * @return array<int,string>
     */
    private function getVisibleNamespaces(): array
    {
        $nsInfo = $this->services->getNamespaceInfo();
        $readable = array_flip(NamespacePermissions::getReadableNamespaces($this->getUser()));
        $validNS = [];

        foreach ($nsInfo->getCanonicalNamespaces() as $nsId => $nsName) {
            if ($nsId >= 0 && !$nsInfo->isTalk($nsId) && $nsId !== NS_USER
                && isset($readable[$nsId])
            ) {
                $validNS[$nsId] = ($nsId === 0) ? $this->msg('blanknamespace')->text() : $nsName;
            }
        }
        ksort($validNS);

        return $validNS;
    }

    /* ============================================================
       Batch Update Handler
       ============================================================ */

    /**
     * Run batch embedding updates for a namespace.
     */
    private function handleBatchUpdate(int $namespace)
    {
        $output = $this->getOutput();

        if (!isset($this->getVisibleNamespaces()[$namespace])) {
            $output->addHTML(Html::errorBox(
                $this->msg('mwassistantembeddings-invalid-namespace')->escaped()
            ));
            return;
        }

        try {
            $stats = $this->getStatsCached();
            $mcpTimestamps = $stats['page_timestamps'] ?? [];
            $mcpRevisions = $stats['page_revisions'] ?? [];

            $rows = $this->fetchPagesWithRevision($namespace);

            $jobQueue = $this->services->getJobQueueGroup();
            $jobs = [];
            $skipped = 0;

            foreach ($rows as $row) {
                $titleObj = Title::newFromRow($row);
                $prefixed = $titleObj->getPrefixedText();
                $pageLatest = (int) $row->page_latest;
                $revTimestamp = $row->rev_timestamp ?? $row->page_touched;

                if (
                    !$this->pageIsOutdated(
                        $prefixed,
                        $pageLatest,
                        $revTimestamp,
                        $mcpRevisions,
                        $mcpTimestamps
                    )
                ) {
                    $skipped++;
                    continue;
                }

                $jobs[] = new UpdateEmbeddingJob($titleObj);
            }

            if ($jobs) {
                // push() handles batches efficiently — single round-trip to the queue.
                $jobQueue->push($jobs);
            }

            $queued = count($jobs);
            $this->renderJustQueuedNotice($queued, $skipped);

        } catch (\Exception $e) {
            $output->addHTML(Html::errorBox(
                $this->msg('mwassistantembeddings-batch-failed')
                    ->plaintextParams($e->getMessage())
                    ->escaped()
            ));
        }
    }

    /**
     * One-shot success notice rendered immediately after a batch submit.
     * Persistent "X jobs in flight" feedback comes from the queue-driven
     * pending banner that runs on every page render.
     */
    private function renderJustQueuedNotice(int $queued, int $skipped): void
    {
        $output = $this->getOutput();

        if ($queued === 0) {
            $output->addHTML(Html::successBox(
                $this->msg('mwassistantembeddings-batch-nothing-to-do')
                    ->numParams($skipped)
                    ->parse()
            ));
            return;
        }

        $output->addHTML(Html::successBox(
            $this->msg('mwassistantembeddings-batch-queued')
                ->numParams($queued, $skipped)
                ->parse()
        ));
    }

    /**
     * Banner driven entirely by the JobQueue: persists across reloads as long
     * as embedding jobs are still in flight, and disappears once the queue
     * drains. This is what lets the user see progress without us auto-refreshing.
     */
    private function renderPendingJobsBanner(): void
    {
        $pending = $this->getPendingEmbeddingJobCount();
        if ($pending === 0) {
            return;
        }

        $body = $this->msg('mwassistantembeddings-jobs-pending')
            ->numParams($pending)
            ->parse();
        $body .= ' ' . $this->renderReloadHint();
        $this->getOutput()->addHTML(Html::rawElement(
            'div',
            ['class' => 'mwassistant-batch-banner'],
            $body
        ));
    }

    private function getPendingEmbeddingJobCount(): int
    {
        $jobQueueGroup = $this->services->getJobQueueGroup();
        $total = 0;
        foreach ([UpdateEmbeddingJob::TYPE, DeleteEmbeddingJob::TYPE] as $type) {
            $queue = $jobQueueGroup->get($type);
            // Unclaimed (waiting) + acquired (currently being run by a runner).
            $total += $queue->getSize() + $queue->getAcquiredCount();
        }
        return $total;
    }

    /**
     * "Reload this page to see progress" — rendered as a same-URL anchor so
     * keyboard / right-click flows work, instead of a JS button.
     */
    private function renderReloadHint(): string
    {
        $url = htmlspecialchars($this->getPageTitle()->getLocalURL(
            $this->getRequest()->getQueryValuesOnly()
        ));
        return Html::rawElement(
            'a',
            [
                'href' => $url,
                'class' => 'mwassistant-batch-reload-link',
            ],
            $this->msg('mwassistantembeddings-reload-hint')->escaped()
        );
    }

    /**
     * Fetch pages joined with their latest revision row.
     *
     * page_latest is the rev_id of the page's current revision. Joining to
     * revision lets us compare against rev_timestamp (the *content* last-modified
     * time) instead of page_touched (which moves on cache invalidation, causing
     * false "outdated" reports).
     *
     * @param int|int[]|null $namespace Single namespace, list, or null for all.
     * @return \Wikimedia\Rdbms\IResultWrapper
     */
    private function fetchPagesWithRevision($namespace = null)
    {
        $dbr = $this->services->getDBLoadBalancer()->getConnection(DB_REPLICA);
        $qb = $dbr->newSelectQueryBuilder()
            ->select([
                'page_id',
                'page_namespace',
                'page_title',
                'page_touched',
                'page_latest',
                'page_len',
                'rev_timestamp',
            ])
            ->from('page')
            ->leftJoin('revision', null, 'page_latest = rev_id')
            ->where(['page_is_redirect' => 0])
            ->caller(__METHOD__);

        if ($namespace !== null) {
            $qb->andWhere(['page_namespace' => $namespace]);
        }

        return $qb->fetchResultSet();
    }

    /**
     * Decide whether a page's stored embedding is out of date.
     *
     * Preferred path: compare rev_id equality (exact identity). Falls back to
     * timestamp comparison against rev_timestamp for legacy embeddings that
     * predate rev_id tracking — but never against page_touched, which is
     * bumped by cache invalidations unrelated to content.
     *
     * @param string $prefixed Prefixed page title (the key MCP uses).
     * @param int $pageLatest Wiki's current rev_id (page_latest).
     * @param string|null $revTimestamp Wiki's rev_timestamp for page_latest.
     * @param array<string,int> $mcpRevisions page_title -> stored rev_id.
     * @param array<string,string> $mcpTimestamps page_title -> stored last_modified.
     */
    private function pageIsOutdated(
        string $prefixed,
        int $pageLatest,
        ?string $revTimestamp,
        array $mcpRevisions,
        array $mcpTimestamps
    ): bool {
        if (isset($mcpRevisions[$prefixed])) {
            return (int) $mcpRevisions[$prefixed] !== $pageLatest;
        }
        if (isset($mcpTimestamps[$prefixed]) && $revTimestamp !== null) {
            return $mcpTimestamps[$prefixed] < $revTimestamp;
        }
        // Embedding has neither rev_id nor a usable timestamp recorded: treat
        // as outdated so the next batch run reconciles it.
        return true;
    }

    /* ============================================================
       Single Page Update Handler
       ============================================================ */

    /**
     * Update embeddings for a single page.
     */
    private function handleSingleUpdate(string $pageName)
    {
        $output = $this->getOutput();

        // Check read permission before existence, and answer both with the
        // same message, so this form is not an existence oracle for pages
        // the user cannot read.
        $title = Title::newFromText($pageName);
        if (!$title
            || !$this->services->getPermissionManager()->quickUserCan('read', $this->getUser(), $title)
            || !$title->exists()
        ) {
            $output->addHTML(Html::errorBox(
                $this->msg('mwassistantembeddings-page-not-found')
                    ->plaintextParams($pageName)
                    ->escaped()
            ));
            return;
        }

        try {
            $this->services->getJobQueueGroup()->push(new UpdateEmbeddingJob($title));
        } catch (\Throwable $e) {
            $output->addHTML(Html::errorBox(
                $this->msg('mwassistantembeddings-queue-failed')
                    ->plaintextParams($e->getMessage())
                    ->escaped()
            ));
            return;
        }

        $output->addHTML(Html::successBox(
            $this->msg('mwassistantembeddings-single-queued')
                ->params($title->getPrefixedText())
                ->parse()
        ));
    }

    /* ============================================================
       Stats + UI Rendering
       ============================================================ */

    /**
     * Render namespace-level statistics and controls.
     */
    private function renderStatsTable()
    {
        $output = $this->getOutput();

        // Persistent banner — visible on every render while jobs are in flight,
        // gone once the queue drains. Independent of whether this request
        // submitted anything.
        $this->renderPendingJobsBanner();

        try {
            $stats = $this->getStatsCached();
            $error = isset($stats['error']);
            $mcpTimestamps = $error ? [] : ($stats['page_timestamps'] ?? []);
            $mcpRevisions = $error ? [] : ($stats['page_revisions'] ?? []);
            $totalVectors = $error ? 0 : ($stats['total_vectors'] ?? 0);
        } catch (\Exception $e) {
            $output->addHTML(Html::errorBox(
                $this->msg('mwassistantembeddings-stats-failed')
                    ->plaintextParams($e->getMessage())
                    ->escaped()
            ));
            return;
        }

        if ($error) {
            $output->addHTML(Html::errorBox(
                $this->msg('mwassistantembeddings-stats-error')
                    ->plaintextParams(
                        $stats['message'] ?? $this->msg('mwassistantembeddings-unknown-error')->text()
                    )
                    ->escaped()
            ));
        }

        // ---- Stats Card ----
        $output->addHTML('<div class="mwassistant-stats-grid">');
        $output->addHTML(
            '<div class="mwassistant-stat-card">
                <div class="mwassistant-stat-label">'
                . $this->msg('mwassistantembeddings-total-vectors')->escaped() . '</div>
                <div class="mwassistant-stat-value">' . htmlspecialchars((string)$totalVectors) . '</div>
             </div>'
        );
        $output->addHTML('</div>');

        // ---- Namespace Status Table ----
        $nsStats = $this->computeNamespaceStats($mcpTimestamps, $mcpRevisions);
        $this->renderNamespaceTable($nsStats);

        // ---- Single Page Update Form ----
        if ($this->canManage()) {
            $this->renderSingleUpdateForm();
        }
    }

    /**
     * Build namespace summary statistics.
     *
     * @param array<string,string> $mcpTimestamps page_title -> last_modified.
     * @param array<string,int> $mcpRevisions page_title -> rev_id.
     */
    private function computeNamespaceStats(array $mcpTimestamps, array $mcpRevisions = []): array
    {
        // Only namespaces the user can read, so page counts for restricted
        // namespaces are not disclosed.
        $validNS = $this->getVisibleNamespaces();
        if (!$validNS) {
            return [];
        }

        $res = $this->fetchPagesWithRevision(array_keys($validNS));

        $stats = [];
        foreach ($validNS as $nsId => $name) {
            $stats[$nsId] = [
                'name' => $name,
                'total' => 0,
                'synced' => 0,
                'outdated' => 0,
                'missing' => 0,
                'skipped' => 0,
            ];
        }

        foreach ($res as $row) {
            $nsId = (int) $row->page_namespace;
            $titleObj = Title::makeTitle($nsId, $row->page_title);
            $prefixed = $titleObj->getPrefixedText();
            $pageLatest = (int) $row->page_latest;
            $revTimestamp = $row->rev_timestamp ?? $row->page_touched;
            $pageLen = (int) $row->page_len;

            $stats[$nsId]['total']++;

            $hasEmbedding = isset($mcpRevisions[$prefixed]) || isset($mcpTimestamps[$prefixed]);

            if ($hasEmbedding) {
                $bucket = $this->pageIsOutdated(
                    $prefixed,
                    $pageLatest,
                    $revTimestamp,
                    $mcpRevisions,
                    $mcpTimestamps
                ) ? 'outdated' : 'synced';
                $stats[$nsId][$bucket]++;
            } else {
                // Match server's heuristic: skip pages too short to embed
                if ($pageLen < 10) {
                    $stats[$nsId]['skipped']++;
                } else {
                    $stats[$nsId]['missing']++;
                }
            }
        }

        return $stats;
    }

    /**
     * Render the namespace status table.
     */
    private function renderNamespaceTable(array $stats)
    {
        $output = $this->getOutput();
        $action = htmlspecialchars($this->getPageTitle()->getLocalURL());
        $canManage = $this->canManage();
        $token = $canManage ? $this->getTokenField() : '';

        $lblSynced = $this->msg('mwassistantembeddings-col-synced')->escaped();
        $lblOutdated = $this->msg('mwassistantembeddings-col-outdated')->escaped();
        $lblSkipped = $this->msg('mwassistantembeddings-pill-skipped')->escaped();
        $lblMissing = $this->msg('mwassistantembeddings-col-missing')->escaped();

        $html = '<h3 class="mwassistant-section-header">'
            . $this->msg('mwassistantembeddings-namespace-status')->escaped() . '</h3>';
        $html .= '<table class="mwassistant-table"><thead><tr>
                    <th>' . $this->msg('mwassistantembeddings-col-namespace')->escaped() . '</th>
                    <th>' . $this->msg('mwassistantembeddings-col-total')->escaped() . '</th>
                    <th>' . $lblSynced . '</th>
                    <th>' . $lblOutdated . '</th>
                    <th>' . $this->msg('mwassistantembeddings-col-skipped')->escaped() . '</th>
                    <th>' . $lblMissing . '</th>'
                    . ($canManage ? '<th>' . $this->msg('mwassistantembeddings-col-action')->escaped() . '</th>' : '') . '
                 </tr></thead><tbody>';

        foreach ($stats as $nsId => $row) {

            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($row['name']) . '</td>';
            $html .= '<td>' . $row['total'] . '</td>';

            // Pills
            $html .= '<td><span class="mwassistant-status-pill synced"><span class="label">' . $lblSynced . '</span><span class="count">' . $row['synced'] . '</span></span></td>';
            $html .= '<td>' . ($row['outdated'] ?
                '<span class="mwassistant-status-pill out-of-date"><span class="label">' . $lblOutdated . '</span><span class="count">' . $row['outdated'] . '</span></span>' :
                '0'
            ) . '</td>';
            $html .= '<td>' . ($row['skipped'] ?
                '<span class="mwassistant-status-pill skipped"><span class="label">' . $lblSkipped . '</span><span class="count">' . $row['skipped'] . '</span></span>' :
                '0'
            ) . '</td>';
            $html .= '<td>' . ($row['missing'] ?
                '<span class="mwassistant-status-pill not-embedded"><span class="label">' . $lblMissing . '</span><span class="count">' . $row['missing'] . '</span></span>' :
                '0'
            ) . '</td>';

            // Action button
            if ($canManage) {
                $html .= '<td>
                            <form method="post" action="' . $action . '" style="margin:0;">
                                ' . Html::hidden('namespace_selector', $nsId) . $token . '
                                <button type="submit" name="batch_update" value="1" class="mwassistant-action-btn">'
                                . $this->msg('mwassistantembeddings-update-button')->escaped() . '</button>
                            </form>
                          </td>';
            }

            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $output->addHTML($html);
    }

    /**
     * Render the single page update UI section.
     */
    private function renderSingleUpdateForm()
    {
        $output = $this->getOutput();
        $action = htmlspecialchars($this->getPageTitle()->getLocalURL());

        $output->addHTML('<h3 class="mwassistant-section-header">'
            . $this->msg('mwassistantembeddings-single-header')->escaped() . '</h3>');
        $output->addHTML(
            '<form method="post" action="' . $action . '" class="mwassistant-single-update-card">'
        );
        $output->addHTML($this->getTokenField());
        $output->addHTML(
            '<label for="page-input" style="font-weight:600;">'
            . $this->msg('mwassistantembeddings-single-label')->escaped() . '</label>'
        );

        // Title widget with autocomplete enabled
        $widget = $this->buildTitleWidget();
        $output->addHTML($widget->toString());

        $output->addHTML(
            '<button type="submit" class="mwassistant-action-btn" style="margin-left:10px;">'
            . $this->msg('mwassistantembeddings-single-button')->escaped() .
            '</button>'
        );
        $output->addHTML('</form>');
    }

    /**
     * Build the OOUI TitleInputWidget with autocomplete configured.
     */
    private function buildTitleWidget(): TitleInputWidget
    {
        return new TitleInputWidget([
            'id' => 'page-input',
            'name' => 'page',
            'placeholder' => $this->msg('mwassistantembeddings-single-placeholder')->text(),
            'required' => true,
            'infusable' => true,
            'autocomplete' => true,
            'showIcons' => true,
            'showRedirects' => true,
            'contentPagesOnly' => false,
            'namespace' => null,
            'apiUrl' => wfScript('api'),
            'classes' => ['mwassistant-input-widget'],
        ]);
    }

    /** @inheritDoc */
    protected function getGroupName(): string
    {
        return 'labki';
    }
}
