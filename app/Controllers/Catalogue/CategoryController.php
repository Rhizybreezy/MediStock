<?php
declare(strict_types=1);

namespace App\Controllers\Catalogue;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\CategoryRepository;
use App\Services\Catalogue\CatalogueService;

final class CategoryController extends Controller
{
    public function __construct(private CategoryRepository $categories, private CatalogueService $service)
    {
    }

    public function index(Request $request): Response
    {
        return view('catalogue/categories/index', ['title' => 'Categories', 'categories' => $this->categories->allWithCounts()]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function edit(Request $request, int $id): Response
    {
        return $this->form($this->findOr404($this->categories->find($id), 'Category'));
    }

    private function form(?array $category): Response
    {
        return view('catalogue/categories/form', [
            'title'    => $category ? 'Edit category' : 'New category',
            'category' => $category,
            'parents'  => array_filter($this->categories->options(false), fn ($c) => !$category || (int) $c['id'] !== (int) $category['id']),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->service->saveCategory(null, $this->validated($request, null));
        flash('success', 'Category created.');
        return redirect(route('catalogue.categories.index'));
    }

    public function update(Request $request, int $id): Response
    {
        $this->findOr404($this->categories->find($id), 'Category');
        $this->service->saveCategory($id, $this->validated($request, $id));
        flash('success', 'Category saved.');
        return redirect(route('catalogue.categories.index'));
    }

    private function validated(Request $request, ?int $id): array
    {
        $data = Validator::validate($request->all(), [
            'code'            => 'required|string|min:2|max:20|unique:inventory_categories,code' . ($id ? ",$id" : '') . '|regex:/^[A-Za-z0-9_-]+$/',
            'name'            => 'required|string|min:2|max:100|unique:inventory_categories,name' . ($id ? ",$id" : ''),
            'parent_id'       => 'nullable|integer|exists:inventory_categories,id',
            'description'     => 'nullable|string|max:255',
            'requires_expiry' => 'nullable|boolean',
            'is_active'       => 'nullable|boolean',
        ]);
        $data['requires_expiry'] = (int) ($data['requires_expiry'] ?? 0);
        $data['is_active'] = (int) ($data['is_active'] ?? 0);
        return $data;
    }
}
