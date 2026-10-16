<?php
namespace BushcraftCN\CCOT\PostImages;
use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;
final class Cleanup extends AbstractCommand
{
    protected function configure(): void {$this->setName('ccot:post-images-cleanup')->setDescription('List unreferenced forum uploads older than 7 days; no deletion by default')->addOption('execute',null,InputOption::VALUE_NONE,'Permanently delete the listed, rechecked unreferenced files');}
    protected function fire(): int {$store=resolve(Store::class);$candidates=$store->candidates();foreach($candidates as$r)$this->output->writeln($r['id'].' '.$r['bytes'].' bytes');$count=$this->input->getOption('execute')?$store->clean(array_column($candidates,'id')):0;$this->output->writeln('Candidates: '.count($candidates).'; deleted: '.$count);return 0;}
}
