<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class Paragraph extends Component
{
  public $description;
  public $style;
  
  /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct($description,  $style = "")
    {

      $this->description = $description;
      $this->style       = $style;
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return View|string
     */
    public function render()
    {
        return view('components.paragraph');
    }
}
