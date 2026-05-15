<?php

namespace NahidFerdous\LaravelModuleGenerator\Contracts;

interface OutputInterface
{
    public function info($string, $verbosity = null);
    public function warn($string, $verbosity = null);
    public function error($string, $verbosity = null);
    public function line($string, $style = null, $verbosity = null);
    public function newLine($count = 1);
}
