<?php

namespace Laravel\Scout\Engines;

use Laravel\Scout\Builder;
use Illuminate\Database\Eloquent\Collection;

class SolrEngine extends Engine
{
    /**
     * The SOLR client.
     *
     * @var \Solarium\Client $solarium
     */
    protected $client;

    /**
     * Create a new engine instance.
     *
     * @param  \Solarium\Client $solarium
     * @return void
     */

    public function __construct(\Solarium\Client $solarium)
    {
        // $this->client = new \Solarium\Client(Config::get('solr'));
        $this->client = $solarium;

    }

    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function update($models)
    {
        $update = $this->client->createUpdate();
        $doc = $update->createDocument();

        $update->addDocuments($models->map(function ($model) use ($update) {
            $array = $model->toSearchableArray();

            if (empty($array)) {
                return;
            }

            foreach($array as $key=>$value) {
                if (!$value) {
                    if (array_key_exists($key, $array)) {
                        unset($array[$key]);
                    }
                }
            }

            // Put in the model name in front
            $array['id'] = get_class($model).$model->getKey();
            $array['model'] = get_class($model);
            return $update->createDocument(array_merge(['objectID' => $model->getKey() ], $array));
            // return ;
        })->filter()->values()->all());

        $update->addCommit();
        $result = $this->client->update($update);

    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function delete($models)
    {

        $query = new Solarium_Query_Update;

        $query->addDeleteByIds(
            $models->map(function ($model) {
                return $model->getKey();
            })->values()->all()
        );

        $query->addCommit();
        $client->update($query);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, array_filter([
            'numericFilters' => $this->filters($builder),
            'hitsPerPage' => $builder->limit,
        ]));
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page) //rows start
    {
        return $this->performSearch($builder, [
            'numericFilters' => $this->filters($builder),
            'hitsPerPage' => $perPage,
            'page' => $page - 1,
        ]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $options
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {

        $query = $this->client->createSelect();
        $query->setQuery($builder->query);
        $resultset = $this->client->select($query);
        return $resultset;

    }

    /**
     * Get the filter array for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return array
     */
    protected function filters(Builder $builder)
    {
        return collect($builder->wheres)->map(function ($value, $key) {
            return $key.'='.$value;
        })->values()->all();
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return collect($results['hits'])->pluck('objectID')->values();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map($results, $model)
    {
        if ($results->getNumFound() === 0) {
            return Collection::make();
        }

        $keys = collect($results)
                        ->pluck('objectID')->values()->all();

        $models = $model->whereIn(
            $model->getQualifiedKeyName(), $keys
        )->get()->keyBy($model->getKeyName());

        return Collection::make($results)->map(function ($hit) use ($model, $models) {
            $key = $hit['objectID'];

            if (isset($models[$key])) {
                return $models[$key];
            }
        })->filter();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results->getNumFound();
    }
}
