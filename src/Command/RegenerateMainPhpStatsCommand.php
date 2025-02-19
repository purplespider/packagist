<?php declare(strict_types=1);

namespace App\Command;

use App\Entity\Package;
use App\Entity\PhpStat;
use App\Util\DoctrineTrait;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Seld\Signal\SignalHandler;
use App\Model\DownloadManager;
use App\Service\Locker;
use Predis\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;

class RegenerateMainPhpStatsCommand extends Command
{
    use DoctrineTrait;

    public function __construct(
        private LoggerInterface $logger,
        private Locker $locker,
        private ManagerRegistry $doctrine,
        private Client $redis,
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('packagist:regenerate-main-php-stats')
            ->setDescription('Regenerates main php stats for a given data point')
            ->addArgument('date', InputArgument::REQUIRED, 'Data point date YYYYMMDD format')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // another migrate command is still active
        $lockAcquired = $this->locker->lockCommand($this->getName());
        if (!$lockAcquired) {
            if ($input->getOption('verbose')) {
                $output->writeln('Aborting, another task is running already');
            }
            return 0;
        }

        $signal = SignalHandler::create(null, $this->logger);

        try {
            // might be a large-ish dataset coming through here
            ini_set('memory_limit', '2G');

            $now = new \DateTimeImmutable();
            $dataPoint = new \DateTimeImmutable($input->getArgument('date'));
            $todaySuffix = ':'.$now->format('Ymd');
            $idsToUpdate = $this->getEM()->getConnection()->fetchFirstColumn(
                'SELECT package_id FROM php_stat WHERE type=:type AND depth=:depth',
                ['type' => PhpStat::TYPE_PHP, 'depth' => PhpStat::DEPTH_MAJOR]
            );

            $phpStatRepo = $this->getEM()->getRepository(PhpStat::class);
            $packageRepo = $this->getEM()->getRepository(Package::class);

            while ($idsToUpdate) {
                $id = array_shift($idsToUpdate);
                $package = $packageRepo->find($id);
                if (!$package) {
                    continue;
                }

                $this->logger->debug('Processing package #'.$id);
                $phpStatRepo->createOrUpdateMainRecord($package, PhpStat::TYPE_PHP, $now, $dataPoint);
                $phpStatRepo->createOrUpdateMainRecord($package, PhpStat::TYPE_PLATFORM, $now, $dataPoint);

                $this->getEM()->clear();
            }
        } finally {
            $this->locker->unlockCommand($this->getName());
        }

        return 0;
    }
}
