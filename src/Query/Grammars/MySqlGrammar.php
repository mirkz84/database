<?php namespace Database\Query\Grammars;

use Database\Query\Builder;
use Database\Query\InfileClause;
use Database\Query\OutfileClause;

class MySqlGrammar extends Grammar
{

    /**
     * The components that make up a select clause.
     *
     * @var array
     */
    protected $selectComponents = array(
        'aggregate',
        'columns',
        'outfile',
        'from',
        'joins',
        'wheres',
        'groups',
        'havings',
        'orders',
        'limit',
        'offset',
        'lock',
    );

    /**
     * Compile a select query into SQL.
     *
     * @param  \Database\Query\Builder
     * @return string
     */
    public function compileSelect(Builder $query)
    {
        $sql = parent::compileSelect($query);

        if ($query->unions) {
            $sql = '(' . $sql . ') ' . $this->compileUnions($query);
        }

        return $sql;
    }

    /**
     * Compile a single union statement.
     *
     * @param  array $union
     * @return string
     */
    protected function compileUnion(array $union)
    {
        $joiner = $union['all'] ? ' union all ' : ' union ';

        return $joiner . '(' . $union['query']->toSql() . ')';
    }

    /**
     * Compile the lock into SQL.
     *
     * @param  \Database\Query\Builder $query
     * @param  bool|string $value
     * @return string
     */
    protected function compileLock(Builder $query, $value)
    {
        if (is_string($value)) return $value;

        return $value ? 'for update' : 'lock in share mode';
    }

    /**
     * Compile an insert statement into SQL.
     *
     * @param  \Database\Query\Builder $query
     * @param  array $values
     * @return string
     */
    public function compileInsertIgnore(Builder $query, array $values)
    {
        return $this->doCompileInsert($query, $values, 'insert ignore');
    }

    /**
     * Compile an insert statement into SQL.
     *
     * @param  \Database\Query\Builder $query
     * @param  array $values
     * @param  array $updateValues
     * @return string
     */
    public function compileInsertOnDuplicateKeyUpdate(Builder $query, array $values, array $updateValues)
    {
        $insert = $this->compileInsert($query, $values);

        $update = $this->getUpdateColumns($updateValues);

        return "$insert on duplicate key update $update";
    }

    /**
     * Compile a replace statement into SQL.
     *
     * @param  \Database\Query\Builder $query
     * @param  array $values
     * @return string
     */
    public function compileReplace(Builder $query, array $values)
    {
        return $this->doCompileInsert($query, $values, 'replace');
    }

    /**
     * @param Builder $insert
     * @param array $columns
     * @param Builder $query
     * @return string
     */
    public function compileInsertIgnoreSelect(Builder $insert, array $columns, Builder $query)
    {
        return $this->doCompileInsertSelect($insert, $columns, $query, 'insert ignore');
    }

    /**
     * @param Builder $insert
     * @param array $columns
     * @param Builder $query
     * @return string
     */
    public function compileReplaceSelect(Builder $insert, array $columns, Builder $query)
    {
        return $this->doCompileInsertSelect($insert, $columns, $query, 'replace');
    }

    /**
     * Compile an insert select on duplicate key update statement into SQL.
     *
     * @param Builder $insert
     * @param array $columns
     * @param Builder $query
     * @param array $updateValues
     * @return string
     */
    public function compileInsertSelectOnDuplicateKeyUpdate(Builder $insert, array $columns, Builder $query, array $updateValues)
    {
        $insert = $this->doCompileInsertSelect($insert, $columns, $query, 'insert');

        $update = $this->getUpdateColumns($updateValues);

        return "$insert on duplicate key update $update";
    }

    /**
     * Compile an update statement into SQL.
     *
     * @param  \Database\Query\Builder $query
     * @param  array $values
     * @return string
     */
    public function compileUpdate(Builder $query, $values)
    {
        $sql = parent::compileUpdate($query, $values);

        if (isset($query->orders)) {
            $sql .= ' ' . $this->compileOrders($query, $query->orders);
        }

        if (isset($query->limit)) {
            $sql .= ' ' . $this->compileLimit($query, $query->limit);
        }

        return rtrim($sql);
    }

    /**
     * Compile a delete statement into SQL.
     *
     * @param  \Database\Query\Builder $query
     * @return string
     */
    public function compileDelete(Builder $query)
    {
        $table = $this->wrapTable($query->from);

        $where = is_array($query->wheres) ? $this->compileWheres($query) : '';

        if (isset($query->joins)) {
            $joins = ' ' . $this->compileJoins($query, $query->joins);

            return trim("delete $table from {$table}{$joins} $where");
        }

        return trim("delete from $table $where");
    }

    /**
     * Wrap a single string in keyword identifiers.
     *
     * @param  string $value
     * @return string
     */
    protected function wrapValue($value)
    {
        if ($value === '*') return $value;

        return '`' . str_replace('`', '``', $value) . '`';
    }

    /**
     * @param Builder $query
     * @param OutfileClause $outfileClause
     * @return string
     */
    protected function compileOutfile(Builder $query, OutfileClause $outfileClause)
    {
        $sqlParts = array("into $outfileClause->type '$outfileClause->file'");

        $optionally = $outfileClause->enclosedBy ? 'optionally ' : '';

        $parts = array(
            'fields' => array(
                'fieldsTerminatedBy'    => 'terminated by',
                'enclosedBy'            => $optionally . 'enclosed by',
                'escapedBy'             => 'escaped by',
            ),
            'lines' => array(
                'linesTerminatedBy'     => 'terminated by',
            )
        );

        if($options = $this->buildBulkComponents($parts, $outfileClause))
        {
            $sqlParts[] = $options;
        }

        return implode(' ', $sqlParts);
    }

    /**
     * @param Builder $query
     * @param InfileClause $infile
     * @return string
     */
    public function compileInfile(Builder $query, InfileClause $infile)
    {
        $local = $infile->local ? 'local ' : '';

        $type = $infile->type ? ($infile->type . ' ') : '';

        $sqlParts = array("load data {$local}infile '$infile->file' {$type}into table " . $this->wrapTable($query->from));

        $optionally = $infile->enclosedBy ? 'optionally ' : '';

        $parts = array(
            'fields' => array(
                'fieldsTerminatedBy'    => 'terminated by',
                'enclosedBy'            => $optionally . 'enclosed by',
                'escapedBy'             => 'escaped by',
            ),
            'lines' => array(
                'linesStartingBy'       => 'starting by',
                'linesTerminatedBy'     => 'terminated by',
            )
        );

        if($options = $this->buildBulkComponents($parts, $infile))
        {
            $sqlParts[] = $options;
        }

        $sqlParts[] = '(' . $this->columnize($infile->columns) . ')';

        $sqlParts[] = $infile->rules ? ('set ' . $this->getUpdateColumns($infile->rules)) : '';

        return implode(' ', $sqlParts);
    }

    /**
     * @param array $parts
     * @param InfileClause|OutfileClause $infile
     * @return string
     */
    private function buildBulkComponents(array $parts, $infile)
    {
        $sqlParts = array();

        foreach ($parts as $type => $components)
        {
            foreach($components as $property => $sql)
            {
                if(!is_null($infile->$property))
                {
                    $sqlParts[] = trim("$type $sql '{$infile->$property}'");

                    $type = '';
                }
            }
        }

        return implode(' ', $sqlParts);
    }
}
