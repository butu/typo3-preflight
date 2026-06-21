<?php

namespace Test\Domain\Model;

class CleanModel
{
    private string $title;
    private string $description;

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }
}
