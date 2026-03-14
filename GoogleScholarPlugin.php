<?php

/**
 * @file plugins/generic/googleScholar/GoogleScholarPlugin.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class GoogleScholarPlugin
 *
 * @ingroup plugins_generic_googleScholar
 *
 * @brief Inject Google Scholar meta tags into submission views to facilitate indexing.
 */

namespace APP\plugins\generic\googleScholar;

use APP\core\Application;
use APP\core\Request;
use APP\facades\Repo;
use APP\submission\Submission;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\i18n\LocaleConversion;

class GoogleScholarPlugin extends GenericPlugin
{
    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (parent::register($category, $path, $mainContextId)) {
            if ($this->getEnabled($mainContextId)) {
                Hook::add('ArticleHandler::view', $this->submissionView(...));
                Hook::add('PreprintHandler::view', $this->submissionView(...));
            }
            return true;
        }
        return false;
    }

    /**
     * Get the name of the settings file to be installed on new context
     * creation.
     *
     * @return string
     */
    public function getContextSpecificPluginSettingsFile()
    {
        return $this->getPluginPath() . '/settings.xml';
    }

    /**
     * Inject Google Scholar metadata into submission landing page view
     *
     * @param string $hookName
     * @param array $args
     *
     * @return boolean
     */
    public function submissionView($hookName, $args)
    {
        $application = Application::get();
        $applicationName = $application->getName();
        /** @var Request */
        $request = $args[0];
        if ($applicationName == 'ojs2') {
            $issue = $args[1];
            $submission = $args[2];
            $submissionPath = 'article';
        }
        if ($applicationName == 'ops') {
            $submission = $args[1];
            $submissionPath = 'preprint';
        }
        /** @var Submission $submission */
        $requestArgs = $request->getRequestedArgs();
        $context = $request->getContext();

        // Only add Google Scholar metadata tags to the canonical URL for the latest version
        // See discussion: https://github.com/pkp/pkp-lib/issues/4870
        if (count($requestArgs) > 1 && $requestArgs[1] === 'version') {
            return false;
        }

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->addHeader('googleScholarRevision', '<meta name="gs_meta_revision" content="1.1"/>');

        // Context identification
        if ($applicationName == 'ojs2') {
            $templateMgr->addHeader('googleScholarJournalTitle', '<meta name="citation_journal_title" content="' . htmlspecialchars($context->getName($context->getPrimaryLocale())) . '"/>');
            if (($abbreviation = $context->getData('abbreviation', $context->getPrimaryLocale())) || ($abbreviation = $context->getData('acronym', $context->getPrimaryLocale()))) {
                $templateMgr->addHeader('googleScholarJournalAbbrev', '<meta name="citation_journal_abbrev" content="' . htmlspecialchars($abbreviation) . '"/>');
            }
            if (($issn = $context->getData('onlineIssn')) || ($issn = $context->getData('printIssn')) || ($issn = $context->getData('issn'))) {
                $templateMgr->addHeader('googleScholarIssn', '<meta name="citation_issn" content="' . htmlspecialchars($issn) . '"/> ');
            }
        }
        if ($applicationName == 'ops') {
            $templateMgr->addHeader('googleScholarPublisher', '<meta name="citation_publisher" content="' . htmlspecialchars($context->getName($context->getPrimaryLocale())) . '"/>');
        }

        // Use the publication passed from the handler (the displayed published version),
        // not getCurrentPublication() which may point to an unpublished draft
        $hookPublication = $applicationName == 'ojs2' ? ($args[3] ?? null) : ($args[2] ?? null);
        $publication = ($hookPublication && (int)$hookPublication->getData('status') === \PKP\submission\PKPSubmission::STATUS_PUBLISHED)
            ? $hookPublication
            : $submission->getCurrentPublication();
        $publicationLocale = $publication->getData('locale');
        $submissionBestId = $publication->getData('urlPath') ?? $submission->getId();

        // Contributors
        $authors = $publication->getData('authors');
        foreach ($authors as $i => $author) {
            $templateMgr->addHeader('googleScholarAuthor' . $i, '<meta name="citation_author" content="' . htmlspecialchars($author->getFullName(false, false, $publicationLocale)) . '"/>');
            foreach($author->getAffiliations() as $affiliation) {
                $templateMgr->addHeader(
                    'googleScholarAuthor' . $i . 'Affiliation' . $affiliation->getId(),
                    '<meta name="citation_author_institution" content="' . htmlspecialchars($affiliation->getLocalizedName($publicationLocale)) . '"/>'
                );
            }
        }

        // Submission title
        $templateMgr->addHeader('googleScholarTitle', '<meta name="citation_title" content="' . htmlspecialchars($publication->getLocalizedFullTitle($publicationLocale)) . '"/>');

        $templateMgr->addHeader('googleScholarLanguage', '<meta name="citation_language" content="' . htmlspecialchars(LocaleConversion::toBcp47($publicationLocale)) . '"/>');

        // Submission publish date and issue information
        if ($applicationName == 'ojs2') {
            if (($datePublished = $publication->getData('datePublished')) && (!$issue || !$issue->getYear() || $issue->getYear() == date('Y', strtotime($datePublished)))) {
                $templateMgr->addHeader('googleScholarDate', '<meta name="citation_date" content="' . date('Y/m/d', strtotime($datePublished)) . '"/>');
            } elseif ($issue && $issue->getYear()) {
                $templateMgr->addHeader('googleScholarDate', '<meta name="citation_date" content="' . htmlspecialchars($issue->getYear()) . '"/>');
            } elseif ($issue && ($datePublished = $issue->getDatePublished())) {
                $templateMgr->addHeader('googleScholarDate', '<meta name="citation_date" content="' . date('Y/m/d', strtotime($datePublished)) . '"/>');
            }
            if ($issue) {
                if ($issue->getShowVolume()) {
                    $templateMgr->addHeader('googleScholarVolume', '<meta name="citation_volume" content="' . htmlspecialchars($issue->getVolume()) . '"/>');
                }
                if ($issue->getShowNumber()) {
                    $templateMgr->addHeader('googleScholarNumber', '<meta name="citation_issue" content="' . htmlspecialchars($issue->getNumber()) . '"/>');
                }
            }
            if ($publication->getData('pages')) {
                if ($startPage = $publication->getStartingPage()) {
                    $templateMgr->addHeader('googleScholarStartPage', '<meta name="citation_firstpage" content="' . htmlspecialchars($startPage) . '"/>');
                }
                if ($endPage = $publication->getEndingPage()) {
                    $templateMgr->addHeader('googleScholarEndPage', '<meta name="citation_lastpage" content="' . htmlspecialchars($endPage) . '"/>');
                }
            }
        }
        if ($applicationName == 'ops') {
            if ($datePublished = $publication->getData('datePublished')) {
                $templateMgr->addHeader('googleScholarDate', '<meta name="citation_date" content="' . date('Y/m/d', strtotime($datePublished)) . '"/>');
                $templateMgr->addHeader('googleScholarOnlineDate', '<meta name="citation_online_date" content="' . date('Y/m/d', strtotime($datePublished)) . '"/>');
                $templateMgr->addHeader('googleScholarPubDate', '<meta name="citation_publication_date" content="' . date('Y/m/d', strtotime($datePublished)) . '"/>');
            }
        }

        // DOI
        if ($doi = $publication->getDoi()) {
            $templateMgr->addHeader('googleScholarPublicationDOI', '<meta name="citation_doi" content="' . htmlspecialchars($doi) . '"/>');
        }
        // URN
        foreach ((array) $templateMgr->getTemplateVars('pubIdPlugins') as $pubIdPlugin) {
            if ($pubId = $publication->getStoredPubId($pubIdPlugin->getPubIdType())) {
                $templateMgr->addHeader('googleScholarPubId' . $pubIdPlugin->getPubIdDisplayType(), '<meta name="citation_' . htmlspecialchars(strtolower($pubIdPlugin->getPubIdDisplayType())) . '" content="' . htmlspecialchars($pubId) . '"/>');
            }
        }

        // Abstract URL
        $templateMgr->addHeader('googleScholarHtmlUrl', '<meta name="citation_abstract_html_url" content="' . $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, $submissionPath, 'view', [$submissionBestId], urlLocaleForPage: '') . '"/>');

        // Abstract
        if ($abstract = $publication->getLocalizedData('abstract', $publicationLocale)) {
            $templateMgr->addHeader('googleScholarAbstract', '<meta name="citation_abstract" xml:lang="' . htmlspecialchars(LocaleConversion::toBcp47($publicationLocale)) . '" content="' . htmlspecialchars(strip_tags($abstract)) . '"/>');
        }

        // Open Graph tags for social sharing and SEO
        $articleUrl = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, $submissionPath, 'view', [$submissionBestId], urlLocaleForPage: '');
        $articleTitle = htmlspecialchars($publication->getLocalizedFullTitle($publicationLocale));
        $articleDescription = $abstract ? htmlspecialchars(mb_substr(strip_tags($abstract), 0, 200, 'utf-8')) : '';

        $templateMgr->addHeader('ogTitle', '<meta property="og:title" content="' . $articleTitle . '"/>');
        $templateMgr->addHeader('ogDescription', '<meta property="og:description" content="' . $articleDescription . '"/>');
        $templateMgr->addHeader('ogUrl', '<meta property="og:url" content="' . $articleUrl . '"/>');
        $templateMgr->addHeader('ogType', '<meta property="og:type" content="article"/>');

        if ($datePublished = $publication->getData('datePublished')) {
            $templateMgr->addHeader('ogPublishedTime', '<meta property="article:published_time" content="' . date('c', strtotime($datePublished)) . '"/>');
        }

        // Twitter Card for better SEO
        $templateMgr->addHeader('twitterCard', '<meta name="twitter:card" content="summary_large_image"/>');
        $templateMgr->addHeader('twitterTitle', '<meta name="twitter:title" content="' . $articleTitle . '"/>');

        // Subjects
        if ($subjects = $publication->getData('subjects', $publicationLocale)) {
            foreach ($subjects as $i => $subject) {
                $subjectName = is_array($subject) ? ($subject['name'] ?? $subject[0] ?? '') : (string) $subject;
                $templateMgr->addHeader(
                    'googleScholarSubject' . $i,
                    '<meta name="citation_keywords" xml:lang="' . htmlspecialchars(LocaleConversion::toBcp47($publicationLocale)) . '" content="' . htmlspecialchars($subjectName) . '"/>'
                );
            }
        }

        // Keywords
        if ($keywords = $publication->getData('keywords', $publicationLocale)) {
            foreach ($keywords as $i => $keyword) {
                $keywordName = is_array($keyword) ? ($keyword['name'] ?? $keyword[0] ?? '') : (string) $keyword;
                $templateMgr->addHeader(
                    'googleScholarKeyword' . $i,
                    '<meta name="citation_keywords" xml:lang="' . htmlspecialchars(LocaleConversion::toBcp47($publicationLocale)) . '" content="' . htmlspecialchars($keywordName) . '"/>'
                );
            }
        }

        // Galley links
        // Google Scholar expects only one citation_pdf_url pointing to the full-text PDF
        // Query the first galley directly via DB to avoid LazyCollection issues
        $firstGalleyRow = \Illuminate\Support\Facades\DB::table('publication_galleys')
            ->where('publication_id', $publication->getId())
            ->orderBy('seq', 'asc')
            ->orderBy('galley_id', 'asc')
            ->first();

        if ($firstGalleyRow) {
            $galleyBestId = $firstGalleyRow->url_path ?? $firstGalleyRow->galley_id;
            $templateMgr->addHeader('googleScholarPdfUrl', '<meta name="citation_pdf_url" content="' . $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, $submissionPath, 'download', [$submissionBestId, $galleyBestId], urlLocaleForPage: '') . '"/>');
        }

        // Citations
        $citations = $publication->getData('citations') ?? [];
        Hook::call('GoogleScholarPlugin::references', [&$citations, $submission->getId()]);
        foreach ($citations as $i => $citation) {
            $templateMgr->addHeader('googleScholarReference' . $i, '<meta name="citation_reference" content="' . htmlspecialchars($citation->getRawCitation()) . '"/>');
        }

        // JSON-LD Schema.org structured data for better SEO
        $articleUrl = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, $submissionPath, 'view', [$submissionBestId], urlLocaleForPage: '');
        $articleTitle = $publication->getLocalizedFullTitle($publicationLocale);
        $articleAbstract = $publication->getLocalizedData('abstract', $publicationLocale) ?? '';

        // Build author array for JSON-LD
        $authorData = [];
        foreach ($authors as $author) {
            $authorItem = ['@type' => 'Person', 'name' => $author->getFullName(false, false, $publicationLocale)];
            $affiliations = $author->getAffiliations();
            if (!empty($affiliations)) {
                $authorItem['affiliation'] = [];
                foreach ($affiliations as $affiliation) {
                    $authorItem['affiliation'][] = ['@type' => 'Organization', 'name' => $affiliation->getLocalizedName($publicationLocale)];
                }
            }
            $authorData[] = $authorItem;
        }

        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'ScholarlyArticle',
            'headline' => $articleTitle,
            'abstract' => strip_tags($articleAbstract),
            'url' => $articleUrl,
            'author' => $authorData,
            'datePublished' => $publication->getData('datePublished'),
            'dateModified' => $publication->getData('lastModified') ?? $publication->getData('datePublished'),
        ];

        // Add publisher
        $jsonLd['publisher'] = [
            '@type' => 'Organization',
            'name' => $context->getName($context->getPrimaryLocale())
        ];

        // Add DOI if available
        if ($doi = $publication->getDoi()) {
            $jsonLd['identifier'] = ['@type' => 'PropertyValue', 'name' => 'doi', 'value' => $doi];
        }

        $templateMgr->addHeader('jsonLdSchema', '<script type="application/ld+json">' . json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>');

        return false;
    }

    /**
     * Get the display name of this plugin
     *
     * @return string
     */
    public function getDisplayName()
    {
        return __('plugins.generic.googleScholar.name');
    }

    /**
     * Get the description of this plugin
     *
     * @return string
     */
    public function getDescription()
    {
        return __('plugins.generic.googleScholar.description');
    }
}
