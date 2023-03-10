
@if(isset($dataTypeContent->{$row->field}))
    <div data-field-name="{{ $row->field }}">
        <a href="#" class="voyager-x remove-single-image" style="position:absolute;"></a>
        <img
                src="@if( !filter_var($dataTypeContent->{$row->field}, FILTER_VALIDATE_URL)){{ Voyager::image( $dataTypeContent->{$row->field} ) }}@else{{ $dataTypeContent->{$row->field} }}@endif"
                data-file-name="{{ $dataTypeContent->{$row->field} }}" data-id="{{ $dataTypeContent->id }}"
                style="max-width:200px; height:auto; clear:both; display:block; padding:2px; border:1px solid #ddd; margin-bottom:10px;">
    </div>
@endif

<input @if($row->required == 1 && !isset($dataTypeContent->{$row->field})) required @endif type="file"
       name="{{ $row->field }}" accept="image/jpeg,image/png,image/jpg,image/webp,image/gif">

<input type="hidden" value="{{$dataTypeContent->{$row->field} }}" id="old_image" name="old_{{ $row->field }}">

