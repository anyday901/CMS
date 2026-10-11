<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\CustomField;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Extra fields on clients and services. */
class CustomFieldController extends Controller
{
    public function index(): View
    {
        return view('admin.custom-fields.index', [
            'clientFields' => CustomField::forClients()->get(),
            'serviceFields' => CustomField::where('entity', CustomField::SERVICE)->with('product')->ordered()->get(),
            'field' => new CustomField(['entity' => CustomField::CLIENT, 'type' => 'text']),
            'products' => Product::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $field = CustomField::create($this->validated($request));
        Activity::record("Added custom field {$field->name}", $field);

        return redirect()->route('admin.custom-fields.index')->with('status', 'Custom field added.');
    }

    public function edit(CustomField $customField): View
    {
        return view('admin.custom-fields.edit', ['field' => $customField, 'products' => Product::orderBy('name')->get()]);
    }

    public function update(Request $request, CustomField $customField): RedirectResponse
    {
        $customField->update($this->validated($request));
        Activity::record("Updated custom field {$customField->name}", $customField);

        return redirect()->route('admin.custom-fields.index')->with('status', 'Custom field updated.');
    }

    /** Saved values stay on clients and services but are no longer shown. */
    public function destroy(CustomField $customField): RedirectResponse
    {
        $customField->delete();
        Activity::record("Deleted custom field {$customField->name}");

        return redirect()->route('admin.custom-fields.index')->with('status', 'Custom field deleted.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'entity' => ['required', Rule::in([CustomField::CLIENT, CustomField::SERVICE])],
            'product_id' => ['nullable', Rule::exists('products', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_keys(CustomField::TYPES))],
            'options' => ['nullable', 'required_if:type,dropdown', 'string', 'max:5000'],
            'help' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        return [
            ...$data,
            'product_id' => $data['entity'] === CustomField::SERVICE ? ($data['product_id'] ?? null) : null,
            'options' => $data['type'] === 'dropdown' ? $data['options'] : null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'required' => $request->boolean('required'),
            'admin_only' => $request->boolean('admin_only'),
        ];
    }
}
