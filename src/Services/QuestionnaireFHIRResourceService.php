<?php

/**
 * FHIR Resource Service class example for implementing the methods that are typically used with FHIR resources via the
 * api.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 *
 * @author    Stephen Nielson <stephen@nielson.org>
 * @copyright Copyright (c) 2022 Stephen Nielson <stephen@nielson.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\DiscoverAndChange\Assessments\Services;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRProvenance;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRQuestionnaire;
use OpenEMR\FHIR\R4\FHIRElement\FHIRId;
use OpenEMR\FHIR\R4\FHIRElement\FHIRMeta;
use OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource;
use OpenEMR\Modules\DiscoverAndChange\Assessments\Services\FhirServices\AssessmentFHIRResourceService;
use OpenEMR\Modules\DiscoverAndChange\Assessments\Services\FhirServices\LibraryAssetFHIRResourceService;
use OpenEMR\Modules\DiscoverAndChange\Assessments\Services\FhirServices\QuestionnaireFormFHIRResourceService;
use OpenEMR\Services\FHIR\FhirProvenanceService;
use OpenEMR\Services\FHIR\FhirServiceBase;
use OpenEMR\Services\FHIR\IResourceReadableService;
use OpenEMR\Services\FHIR\IResourceSearchableService;
use OpenEMR\Services\FHIR\Traits\FhirServiceBaseEmptyTrait;
use OpenEMR\Services\FHIR\Traits\MappedServiceCodeTrait;
use OpenEMR\Services\FHIR\UtilsService;
use OpenEMR\Services\QuestionnaireService;
use OpenEMR\Services\Search\FhirSearchParameterDefinition;
use OpenEMR\Services\Search\SearchFieldType;
use OpenEMR\Services\Search\ServiceField;
use OpenEMR\Services\Search\TokenSearchField;
use OpenEMR\Services\Search\TokenSearchValue;
use OpenEMR\Validators\ProcessingResult;

class QuestionnaireFHIRResourceService extends FhirServiceBase implements IResourceReadableService, IResourceSearchableService
{
    /**
     * If you'd prefer to keep out the empty methods that are doing nothing uncomment the following helper trait
     */
    use FhirServiceBaseEmptyTrait;
    use MappedServiceCodeTrait;


    public function __construct(AssessmentFHIRResourceService $assessmentService = null, QuestionnaireFormFHIRResourceService $questionnaireService = null, LibraryAssetFHIRResourceService $libraryAssetService = null)
    {
        parent::__construct();
//        $this->assessmentService = new AssessmentFHIRResourceService();
//        $this->questionnaireService = new QuestionnaireFormFHIRResourceService();
        $this->addMappedService($assessmentService);
        $this->addMappedService($libraryAssetService);
        $this->addMappedService($questionnaireService);
    }

    /**
     * This method returns the FHIR search definition objects that are used to map FHIR search fields to OpenEMR fields.
     * Since the mapping can be one FHIR search object to many OpenEMR fields, we use the search definition objects.
     * Search fields can be combined as Composite fields and represent a host of search options.
     * @see https://www.hl7.org/fhir/search.html to see the types of search operations, and search types that are available
     * for use.
     * @return array
     */
    protected function loadSearchParameters()
    {
        return  [
            '_id' => new FhirSearchParameterDefinition('_id', SearchFieldType::TOKEN, [new ServiceField('uuid', ServiceField::TYPE_UUID)])
            // note what we store in the database is the Title of the questionnaire even thought its called 'name'.  The computable name is stored only in the json
            // TODO: @adunsulag look at adding a database field for the computable name and store it in the database
            ,'title' => new FhirSearchParameterDefinition('title', SearchFieldType::STRING, [new ServiceField('name', ServiceField::TYPE_STRING)])
            ,'questionnaire-code' => new FhirSearchParameterDefinition('questionnaire-code', SearchFieldType::TOKEN, [new ServiceField('code', ServiceField::TYPE_STRING)])
        ];
    }

    /**
     * Retrieves all of the fhir observation resources mapped to the underlying openemr data elements.
     * @param $fhirSearchParameters The FHIR resource search parameters
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @return processing result
     */
    public function getAll($fhirSearchParameters, $puuidBind = null): ProcessingResult
    {
        $fhirSearchResult = new ProcessingResult();
        try {
            if (isset($fhirSearchParameters['questionnaire-code'])) {
                $service = $this->getServiceForCode(
                    new TokenSearchField('questionnaire-code', $fhirSearchParameters['questionnaire-code']),
                    ''
                );
                // if we have a service let's search on that
                if (isset($service)) {
                    $fhirSearchResult = $service->getAll($fhirSearchParameters, $puuidBind);
                } else {
                    $fhirSearchResult = $this->searchAllServices($fhirSearchParameters, $puuidBind);
                }
            } else {
                $fhirSearchResult = $this->searchAllServices($fhirSearchParameters, $puuidBind);
            }
        } catch (SearchFieldException $exception) {
            $systemLogger = new SystemLogger();
            $systemLogger->errorLogCaller("Failed to retrieve records", ['message' => $exception->getMessage(),
                'field' => $exception->getField(), 'trace' => $exception->getTraceAsString()]);
            // put our exception information here
            $fhirSearchResult->setValidationMessages([$exception->getField() => $exception->getMessage()]);
        }
        return $fhirSearchResult;
    }
    /**
     * Healthcare resources often need to provide an AUDIT trail of who last touched a resource and when was it modified.
     * The ownership and AUDIT trail in FHIR is done via the Provenance record.
     * @param FHIRDomainResource $dataRecord The record we are generating a provenance from
     * @param bool $encode Whether to serialize the record or not
     * @return FHIRProvenance
     */
    public function createProvenanceResource($dataRecord, $encode = false)
    {
        // we don't return any provenance authorship for this custom resource
        // if we did return it, we would fill out the following record
//        $provenance = new FHIRProvenance();
        if (!($dataRecord instanceof FHIRQuestionnaire)) {
            throw new \BadMethodCallException("Data record should be correct instance class");
        }
        $fhirProvenanceService = new FhirProvenanceService();
        // provenance will just be the organization as we don't keep track of the user at the individual FHIR resource level
        // note we do track this internally in OpenEMR but FHIR R4 doesn't expose this as far as I can tell.
        $fhirProvenance = $fhirProvenanceService->createProvenanceForDomainResource($dataRecord, null);
        if ($encode) {
            return json_encode($fhirProvenance);
        } else {
            return $fhirProvenance;
        }
        $provenenance = new FHIRProvenance();
        UtilsService::createProvenanceResource($provenenance, $dataRecord, $encode);
        return null;
    }
}
