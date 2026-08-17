<?php

declare(strict_types=1);

namespace App\Schema;

use App\Entity\Package;
use Spatie\SchemaOrg\ItemList;
use Spatie\SchemaOrg\Organization;
use Spatie\SchemaOrg\Person;
use Spatie\SchemaOrg\Schema;
use Spatie\SchemaOrg\SoftwareSourceCode;
use Spatie\SchemaOrg\WebPage;
use Spatie\SchemaOrg\WebSite;
use Survos\SchemaOrgBundle\Graph\SchemaOrgGraph;
use Survos\SchemaOrgBundle\Mapping\SchemaOrgMapper;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Package → JSON-LD, using both halves of survos/schema-org-bundle.
 *
 * The declarative scalars (name, description, version, codeRepository, keywords,
 * dateModified) live as #[SchemaProperty] attributes on the Package entity and are
 * mapped by SchemaOrgMapper. Everything that needs a decision — who the authors
 * are, what a star count means, how the collection page is shaped — is written
 * here. That split is the one the bundle documents, on a real entity.
 *
 * A bundle is SoftwareSourceCode, not SoftwareApplication: it is source you
 * compose into an app, not an application anyone installs and runs. The
 * distinction costs us Google's software rich result, which only SoftwareApplication
 * gets — but claiming to be an application to win a SERP feature would simply be
 * false, and Google's own guidance for that type expects consumer apps with
 * offers and ratings.
 */
final readonly class PackageSchema
{
    public function __construct(
        private SchemaOrgGraph $schemaOrg,
        private SchemaOrgMapper $mapper,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /** The detail page: one SoftwareSourceCode, plus the people and org behind it. */
    public function addPackage(Package $package, string $siteUrl): SoftwareSourceCode
    {
        $siteUrl = rtrim($siteUrl, '/');
        $canonicalUrl = $this->packageUrl($package);
        $packageId = $canonicalUrl . '#package';

        // Attribute-mapped scalars first; the node comes back as ordinary spatie fluent
        // code, and $base feeds any {base} in an idPattern.
        $node = $this->mapper->add($package, $packageId, $siteUrl);
        \assert($node instanceof SoftwareSourceCode);

        $node
            ->url($canonicalUrl)
            ->programmingLanguage(Schema::computerLanguage()->name('PHP'));

        if (\is_string($package->data['homepage'] ?? null) && '' !== $package->data['homepage']) {
            $node->sameAs($package->data['homepage']);
        }

        $this->addRuntimePlatform($package, $node);
        $this->addPeople($package, $node, $siteUrl);
        $this->addUsageStatistics($package, $node);

        $webPage = $this->webPage($canonicalUrl, $package->name ?? $package->id, $siteUrl);
        $webPage->mainEntity($node->referenced());
        $node->mainEntityOfPage($webPage->referenced());

        return $node;
    }

    /**
     * The listing page: a CollectionPage whose mainEntity is an ItemList of
     * ListItems, each pointing at a package's own node by @id.
     *
     * ListItem + position, rather than a bare array, because the order of a listing
     * is information — and it is what tells a consumer this is page N of a list
     * rather than an unordered bag.
     *
     * @param iterable<Package> $packages
     */
    public function addPackageList(iterable $packages, string $siteUrl, string $canonicalUrl, string $name): void
    {
        $siteUrl = rtrim($siteUrl, '/');

        $items = [];
        $position = 0;
        foreach ($packages as $package) {
            // Each package is contributed as a full node too, so the list references
            // real entries in this same graph rather than dangling @ids.
            $node = $this->addPackage($package, $siteUrl);

            $items[] = Schema::listItem()
                ->position(++$position)
                ->url($this->packageUrl($package))
                ->item($node->referenced());
        }

        $listId = $canonicalUrl . '#list';
        $list = $this->schemaOrg->getOrCreate(ItemList::class, $listId);
        $list
            ->identifier($listId)
            ->numberOfItems($position)
            ->itemListOrder('https://schema.org/ItemListOrderAscending')
            ->itemListElement($items);

        $collectionId = $canonicalUrl . '#collectionpage';
        $collection = $this->schemaOrg->getOrCreate(\Spatie\SchemaOrg\CollectionPage::class, $collectionId);
        $collection
            ->identifier($collectionId)
            ->url($canonicalUrl)
            ->name($name)
            ->isPartOf($this->website($siteUrl)->referenced())
            ->mainEntity($list->referenced());
    }

    /**
     * The PHP and Symfony versions a bundle runs on. schema.org's runtimePlatform is
     * free text, so these are the human-readable constraint strings rather than the
     * parsed arrays — "^8.2 || ^8.3" says more than ["8.2","8.3"].
     */
    private function addRuntimePlatform(Package $package, SoftwareSourceCode $node): void
    {
        $platforms = [];
        if (\is_string($package->phpVersionString) && '' !== trim($package->phpVersionString)) {
            $platforms[] = 'PHP ' . trim($package->phpVersionString);
        }
        if (\is_string($package->symfonyVersionString) && '' !== trim($package->symfonyVersionString)) {
            $platforms[] = 'Symfony ' . trim($package->symfonyVersionString);
        }

        if ([] !== $platforms) {
            $node->runtimePlatform($platforms);
        }
    }

    /**
     * composer.json authors become Person nodes; the vendor becomes the maintaining
     * Organization.
     *
     * Both are keyed on a URL derived from the natural key the source already has
     * (the author's name, the vendor segment of the package name), so one person
     * maintaining twelve bundles is one node in the graph rather than twelve.
     */
    private function addPeople(Package $package, SoftwareSourceCode $node, string $siteUrl): void
    {
        $authors = [];
        foreach ($package->data['authors'] ?? [] as $author) {
            $name = \is_array($author) && \is_string($author['name'] ?? null) ? trim($author['name']) : '';
            if ('' === $name) {
                continue;
            }

            $authorId = $siteUrl . '/people/' . rawurlencode(mb_strtolower($name));
            $person = $this->schemaOrg->getOrCreate(Person::class, $authorId);
            $person->identifier($authorId)->name($name);

            // Deliberately not the email: composer.json publishes it, we don't have to.
            if (\is_string($author['homepage'] ?? null) && '' !== $author['homepage']) {
                $person->url($author['homepage']);
            }

            $authors[] = $person->referenced();
        }

        if ([] !== $authors) {
            $node->author($authors);
        }

        if (\is_string($package->vendor) && '' !== $package->vendor) {
            $vendorId = $siteUrl . '/vendors/' . rawurlencode(mb_strtolower($package->vendor));
            $vendor = $this->schemaOrg->getOrCreate(Organization::class, $vendorId);
            $vendor->identifier($vendorId)->name($package->vendor);

            $node->maintainer($vendor->referenced());
        }
    }

    /**
     * GitHub stars and Packagist downloads, as InteractionCounters.
     *
     * NOT aggregateRating, however tempting: that is the property with a rich result,
     * but a star is not a rating. There is no scale, no reviewer, and nothing being
     * rated — synthesising "4.7 stars" from a star count would be publishing a number
     * nobody stated. interactionStatistic is the honest and conventional encoding.
     */
    private function addUsageStatistics(Package $package, SoftwareSourceCode $node): void
    {
        $statistics = [];

        if (null !== $package->stars && $package->stars > 0) {
            $statistics[] = Schema::interactionCounter()
                ->interactionType(Schema::likeAction())
                ->userInteractionCount($package->stars);
        }

        if (null !== $package->downloads && $package->downloads > 0) {
            $statistics[] = Schema::interactionCounter()
                ->interactionType(Schema::downloadAction())
                ->userInteractionCount($package->downloads);
        }

        if ([] !== $statistics) {
            $node->interactionStatistic($statistics);
        }
    }

    private function webPage(string $canonicalUrl, string $name, string $siteUrl): WebPage
    {
        $webPageId = $canonicalUrl . '#webpage';
        $webPage = $this->schemaOrg->getOrCreate(WebPage::class, $webPageId);

        return $webPage
            ->identifier($webPageId)
            ->url($canonicalUrl)
            ->name($name)
            ->isPartOf($this->website($siteUrl)->referenced());
    }

    private function website(string $siteUrl): WebSite
    {
        $websiteId = $siteUrl . '/#website';
        $website = $this->schemaOrg->getOrCreate(WebSite::class, $websiteId);

        return $website
            ->identifier($websiteId)
            ->url($siteUrl)
            ->name('Survos Packages');
    }

    private function packageUrl(Package $package): string
    {
        return $this->urlGenerator->generate(
            'package_show',
            ['packageId' => $package->id],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
