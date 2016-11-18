<?php
/**
 * File containing the DeleteSubtree class.
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 * @version //autogentag//
 */
namespace EzSystems\CookbookBundle\Command;

use eZ\Publish\API\Repository\Values\Content\Content;
use eZ\Publish\API\Repository\Values\Content\Location;
use eZ\Publish\API\Repository\Values\ContentType\ContentType;
use eZ\Publish\Core\Repository\ContentService;
use eZ\Publish\Core\Repository\ContentTypeService;
use eZ\Publish\Core\Repository\LocationService;
use eZ\Publish\Core\Repository\Repository;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TranslationSubtreeCommand extends ContainerAwareCommand
{

    /** @var  OutputInterface */
    protected $output;

    /** @var Repository */
    protected $repository;

    /** @var ContentService */
    protected $contentService;

    /** @var ContentTypeService */
    protected $contentTypeService;

    /** @var LocationService */
    protected $locationService;

    /** @var string */
    protected $nfsPath;

    /** @var ContentType[] */
    private $contentTypes = array();

    /** @var $counter */
    private $counter = 0;

    protected function configure()
    {
        $this
            ->setName('ezpublish:cookbook:translate_subtree')
            ->setDescription('Translate subtree into a given language')

            ->addOption('escape-translated', null, InputOption::VALUE_NONE, 'If an object is already translated, do not modify it', null)

            ->addArgument('parent-node-id', InputArgument::REQUIRED, 'The subtree\'s parent node id')
            ->addArgument('reference-language', InputArgument::REQUIRED, 'The language used as base eg : eng-GB')
            ->addArgument('target-language', InputArgument::REQUIRED, 'The new language  : eng-GB')

            ->setHelp('This command translates a subtree, into a given language, based on reference language.');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        /*
         * Init variables
         */
        $this->repository = $this->getContainer()->get('ezpublish.api.repository');

        $this->repository->setCurrentUser( $this->repository->getUserService()->loadUser( 14 ) );

        $this->contentService = $this->repository->getContentService();
        $this->contentTypeService = $this->repository->getContentTypeService();
        $this->locationService = $this->repository->getLocationService();

        $this->nfsPath = $this->getContainer()->getParameter("ezpublish_legacy.default.nfs_path");

        /* Check & format input's data */
        $data = $this->validateInput($input);
        if ($data['error']) {
            return 1;
        }

        /* Fetch objects to translate */
        $contentsToTranslate = $this->getAllLocationsToTranslate($data['parent_node'], $data['parent_node'], $data['reference-language']);

        /* Translate objects */
        $this->translateSubtree($contentsToTranslate, $data['reference-language'], $data['target-language'], $data['escape-translated']);

        $this->output->writeln(sprintf("%s object have(s) been translated", $this->counter));

        return 0;
    }

    /**
     * @param InputInterface $input
     * @return array
     */
    private function validateInput(InputInterface $input)
    {
        $data = array(
            'error' => false,
            'parent_node' => "",
            'reference-language' => "",
            'target-language' => "",
            'escape-translated' => (bool)$input->getOption('escape-translated'),
        );

        $locationId = $input->getArgument( 'parent-node-id' );
        try {
            $data['parent_node'] = $this->locationService->loadLocation($locationId);
        }
        catch (\Exception $e) {
            $data['error'] = true;
            $this->output->writeln( "No location with id $locationId" );
        }

        $referenceLanguageCode = $input->getArgument( 'reference-language' );
        try {
            $this->repository->getContentLanguageService()->loadLanguage($referenceLanguageCode);
            $data['reference-language'] = $referenceLanguageCode;
        }
        catch (\Exception $e) {
            $data['error'] = true;
            $this->output->writeln( "No language with code $referenceLanguageCode" );
        }

        $targetLanguageCode = $input->getArgument( 'target-language' );
        try {
            $this->repository->getContentLanguageService()->loadLanguage($targetLanguageCode);
            $data['target-language'] = $targetLanguageCode;
        }
        catch (\Exception $e) {
            $data['error'] = true;
            $this->output->writeln( "No language with code $targetLanguageCode" );
        }

        return $data;
    }

    /**
     * @param Location $rootLocation
     * @param Location $location
     * @param string $refLanguage
     * @return array
     * @throws \eZ\Publish\Core\Base\Exceptions\InvalidArgumentValue
     */
    private function getAllLocationsToTranslate(Location $rootLocation, Location $location, $refLanguage)
    {
        $data = array();

        try {
            if ($rootLocation->id == $location->id) {
                $data[$location->id]['content'] = $this->contentService->loadContentByContentInfo($location->contentInfo, array($refLanguage));
            }

            foreach ($this->locationService->loadLocationChildren($location)->locations as $location) {
                try {
                    $dataTemp = [
                        'content' => $this->contentService->loadContentByContentInfo($location->contentInfo, array($refLanguage))
                    ];

                    $children = $this->getAllLocationsToTranslate($rootLocation, $location, $refLanguage);
                    if ($children) {
                        $dataTemp['children'] = $children;
                    }

                    $data[$location->id] = $dataTemp;
                }
                catch (\Exception $e) {
                    continue;
                }
            }
        }
        catch (\Exception $e) {
            echo $e->getMessage();
        }

        return $data;
    }

    /**
     * @param array $subtreeContents
     * @param string $refLanguage
     * @param string $targetLanguage
     * @param boolean $escapeTranslated
     */
    private function translateSubtree(array $subtreeContents, $refLanguage, $targetLanguage, $escapeTranslated)
    {
        foreach ($subtreeContents as $id => $item) {
            /** @var Content $content */
            $content = $item['content'];

            $this->output->write(sprintf("Node %d - Object %d : ", $id, $content->id), false);

            if ($escapeTranslated) {
                try {
                    $this->contentService->loadContent($content->id, array($targetLanguage), null, false);
                    $this->output->writeln("<info>escaped</info>");
                    continue;
                }
                catch (\Exception $e) {

                }
            }

            try {
                $contentDraft = $this->contentService->createContentDraft( $content->versionInfo->contentInfo );

                $contentUpdateStruct = $this->contentService->newContentUpdateStruct();
                $contentUpdateStruct->initialLanguageCode = $targetLanguage;

                $contentTypeDefinition = $this->getContentTypeDefinition($content);
                foreach ($contentTypeDefinition as $field)
                {
                    if ($field->fieldTypeIdentifier == 'ezimage')
                    {
                        if ($content->getFieldValue($field->identifier, $refLanguage)->uri != null)
                        {
                            $contentUpdateStruct->setField( $field->identifier, $this->nfsPath.$content->getFieldValue($field->identifier, $refLanguage)->id );
                        }
                        else {
                            $contentUpdateStruct->setField( $field->identifier, new \eZ\Publish\Core\FieldType\Image\Value() );
                        }
                    }
                    else
                    {
                        $contentUpdateStruct->setField( $field->identifier, $content->getFieldValue($field->identifier, $refLanguage) );
                    }
                }

                $contentDraft = $this->contentService->updateContent( $contentDraft->versionInfo, $contentUpdateStruct );

                $this->contentService->publishVersion( $contentDraft->versionInfo );
                $this->output->writeln("<info>OK</info>");

                $this->counter++;

                if (isset($item['children'])) {
                    $this->translateSubtree($item['children'], $refLanguage, $targetLanguage, $escapeTranslated);
                }
            }
            catch (\Exception $e) {
                $this->output->writeln("<error>KO</error>");
                $this->output->writeln(sprintf("Error is <error>%s</error>", $e->getMessage()) );
            }
        }
    }

    /**
     * @param Content $content
     * @return \eZ\Publish\API\Repository\Values\ContentType\FieldDefinition[]
     */
    protected function getContentTypeDefinition(Content $content)
    {
        $id = $content->versionInfo->contentInfo->contentTypeId;
        if (!isset($this->contentTypes[$id])) {
            $this->contentTypes[$id] = $this->contentTypeService->loadContentType($id);
        }

        return $this->contentTypes[$id]->fieldDefinitions;
    }
}
