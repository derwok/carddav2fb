<?php

namespace Andig;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UploadCommand extends Command
{
    use ConfigTrait;

    protected function configure()
    {
        $this->setName('upload')
            ->setDescription('Upload to Fritz!Box')
            ->addArgument('filename', InputArgument::REQUIRED, 'filename');

        $this->addConfig();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->loadConfig($input);

        $filename = $input->getArgument('filename');
        $xmlPhonebookStr = file_get_contents($filename);
        $xmlPhonebook = simplexml_load_string($xmlPhonebookStr);

        error_log("Uploading Fritz!Box phonebook");
        uploadPhonebook($xmlPhonebook, $this->config);
        error_log("Successful uploaded new Fritz!Box phonebook");
    }
}
