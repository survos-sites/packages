#!/usr/bin/env bash
# Usage: bash create-url-workflow-demo.sh /tmp/url-workflow [URL ...]
# Requires Symfony CLI, Composer, PHP 8.5+, and pdo_sqlite.
set -euo pipefail
app=${1:-url-workflow}
if (($#)); then shift; fi
if [[ -e "$app" ]]; then
    echo "Refusing to overwrite existing path: $app" >&2
    exit 1
fi
# --version=next is the Symfony CLI spelling of the next development release.
symfony new "$app" --version=next --webapp --no-git --no-interaction
cd "$app"
php bin/console make:entity Url --no-interaction
composer require survos/workflow-extras-bundle:dev-main --no-interaction
# This demo owns only its fresh SQLite database, never the packages app database.
cat > .env.local <<'END'
DATABASE_URL="sqlite:///%kernel.project_dir%/var/url-demo.db"
END
# Enable native workflow attribute discovery.
cat > config/packages/workflow.yaml <<'END'
workflow: ~
END
mkdir -p src/Workflow src/Command src/Controller templates/url
cat > src/Entity/Url.php <<'END'
<?php
namespace App\Entity;

use App\Workflow\UrlPlace;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Url
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 2048)]
    public string $address;

    #[ORM\Column(enumType: UrlPlace::class)]
    public UrlPlace $marking = UrlPlace::Discovered;

    #[ORM\Column(nullable: true)]
    public ?int $statusCode = null;

    #[ORM\Column(nullable: true)]
    public ?int $bytes = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $error = null;

    public function isValid(): bool
    {
        return false !== filter_var($this->address, FILTER_VALIDATE_URL)
            && in_array(strtolower(parse_url($this->address, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true);
    }
}
END
cat > src/Workflow/UrlPlace.php <<'END'
<?php
namespace App\Workflow;

use Survos\WorkflowExtrasBundle\Attribute\Place;

enum UrlPlace: string
{
    #[Place('A URL ready for validation', next: [UrlWorkflow::VALIDATE, UrlWorkflow::REJECT], initial: true)]
    case Discovered = 'discovered';

    #[Place('A valid HTTP or HTTPS URL', next: UrlWorkflow::FETCH)]
    case Validated = 'validated';

    #[Place('Not a valid HTTP or HTTPS URL')]
    case Invalid = 'invalid';

    #[Place('Fetch attempted; inspect status code, bytes and error')]
    case Fetched = 'fetched';
}
END
cat > src/Workflow/UrlWorkflow.php <<'END'
<?php
namespace App\Workflow;

use App\Entity\Url;
use Survos\WorkflowExtrasBundle\Attribute\AsWorkflow;
use Survos\WorkflowExtrasBundle\Attribute\Transition;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsWorkflow(supports: [Url::class], name: self::NAME, places: UrlPlace::class)]
final class UrlWorkflow
{
    public const NAME = 'url';

    #[Transition(UrlPlace::Discovered, UrlPlace::Validated, guard: 'subject.isValid()')]
    public const VALIDATE = 'validate';

    #[Transition(UrlPlace::Discovered, UrlPlace::Invalid, guard: '!subject.isValid()')]
    public const REJECT = 'reject';

    #[Transition(UrlPlace::Validated, UrlPlace::Fetched, 'Fetch synchronously, recording HTTP status and response bytes')]
    public const FETCH = 'fetch';

    public function __construct(private HttpClientInterface $client) {}

    #[AsTransitionListener(self::NAME, self::REJECT)]
    public function reject(TransitionEvent $event): void
    {
        $event->getSubject()->error = 'Expected a valid HTTP or HTTPS URL.';
    }

    #[AsTransitionListener(self::NAME, self::FETCH)]
    public function fetch(TransitionEvent $event): void
    {
        $url = $event->getSubject();
        try {
            $response = $this->client->request('GET', $url->address, ['timeout' => 10, 'max_duration' => 20]);
            $url->statusCode = $response->getStatusCode();
            // false retains bodies for HTTP errors (404, 500, ...).
            $url->bytes = strlen($response->getContent(false));
        } catch (TransportExceptionInterface $exception) {
            $url->error = $exception->getMessage();
        }
    }
}
END
# Persistence policy is app-owned; kickoff and next selection are bundle-owned.
# The bundle calls this adapter after insertion and each committed transition.
cat > src/Workflow/UrlContinuation.php <<'END'
<?php
namespace App\Workflow;

use App\Entity\Url;
use Doctrine\ORM\EntityManagerInterface;
use Survos\WorkflowExtrasBundle\Contract\WorkflowContinuationInterface;
use Survos\WorkflowExtrasBundle\Service\NextTransitionRunner;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\Transition;

final class UrlContinuation implements WorkflowContinuationInterface
{
    public function __construct(
        private Registry $registry,
        private NextTransitionRunner $runner,
        private EntityManagerInterface $manager,
    ) {}

    public function supports(object $subject, ?string $workflowName = null): bool
    {
        return $subject instanceof Url && ($workflowName === null || $workflowName === UrlWorkflow::NAME);
    }

    public function kickoff(object $subject): void
    {
        if ($subject->marking === UrlPlace::Discovered) {
            $this->continueAfterFlush($subject);
        }
    }

    public function continueAfterFlush(object $subject, array $context = [], ?Transition $previous = null): void
    {
        $this->runner->continue($this->registry->get($subject, UrlWorkflow::NAME), $subject, $context, $previous);
        $this->manager->flush();
    }
}
END
cat > src/Command/LoadUrlsCommand.php <<'END'
<?php
namespace App\Command;

use App\Entity\Url;
use App\Workflow\UrlPlace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:load-urls', description: 'Insert URLs and follow next transitions synchronously')]
final class LoadUrlsCommand extends Command
{
    public function __construct(private EntityManagerInterface $manager) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('urls', InputArgument::IS_ARRAY, 'Trusted URLs to fetch');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];
        foreach ($input->getArgument('urls') ?: ['https://example.com', 'not-a-url'] as $address) {
            $url = new Url();
            $url->address = $address;
            $url->marking = UrlPlace::Discovered;
            $this->manager->persist($url);
            $this->manager->flush(); // Bundle kickoff follows next; no transition call here.
            $rows[] = [$url->address, $url->marking->value, $url->statusCode, $url->bytes, $url->error];
        }
        (new SymfonyStyle($input, $output))->table(['URL', 'Place', 'Status', 'Bytes', 'Error'], $rows);
        return Command::SUCCESS;
    }
}
END
cat > src/Controller/HomeController.php <<'END'
<?php
namespace App\Controller;

use App\Entity\Url;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function __invoke(EntityManagerInterface $manager): Response
    {
        return $this->render('url/index.html.twig', ['urls' => $manager->getRepository(Url::class)->findBy([], ['id' => 'ASC'])]);
    }
}
END
cat > templates/url/index.html.twig <<'END'
{% extends 'base.html.twig' %}
{% block title %}URL workflow{% endblock %}
{% block body %}
<h1>URL workflow</h1>
<p>Discovered → Validated → Fetched, or Discovered → Invalid. All transitions run synchronously.</p>
<table cellpadding="10">
    <thead><tr><th>URL</th><th>Place</th><th>Status</th><th>Bytes</th><th>Error</th></tr></thead>
    <tbody>{% for url in urls %}
        <tr><td>{{ url.address }}</td><td>{{ url.marking.value }}</td><td>{{ url.statusCode ?? '—' }}</td><td>{{ url.bytes ?? '—' }}</td><td>{{ url.error ?? '—' }}</td></tr>
    {% else %}<tr><td colspan="5">Run bin/console app:load-urls</td></tr>{% endfor %}</tbody>
</table>
{% endblock %}
END
php bin/console make:migration --no-interaction
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console lint:container
php bin/console app:load-urls "$@"
printf '\nDemo ready in %s\nRun: cd %q && symfony server:start\n' "$PWD" "$PWD"
