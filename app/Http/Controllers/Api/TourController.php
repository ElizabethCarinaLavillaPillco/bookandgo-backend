<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tour;
use App\Models\TourImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TourController extends Controller
{
    public function index(Request $request)
    {
        $query = Tour::with(['category', 'agency.user', 'images'])
            ->where('is_published', true)
            ->where('is_active', true);

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%')
                  ->orWhere('location_city', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->filled('location')) {
            $query->where(function ($q) use ($request) {
                $q->where('location_city', 'like', '%' . $request->location . '%')
                  ->orWhere('location_region', 'like', '%' . $request->location . '%');
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }
        if ($request->filled('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        if ($request->filled('min_rating')) {
            $query->where('rating', '>=', $request->min_rating);
        }

        if ($request->filled('duration')) {
            switch ($request->duration) {
                case 'short':
                    $query->where('duration_days', 0)->where('duration_hours', '<', 4);
                    break;
                case 'medium':
                    $query->where('duration_days', 0)->whereBetween('duration_hours', [4, 8]);
                    break;
                case 'day':
                    $query->where('duration_days', 1);
                    break;
                case 'multi':
                    $query->where('duration_days', '>', 1);
                    break;
            }
        }

        if ($request->filled('difficulty')) {
            $query->where('difficulty_level', $request->difficulty);
        }

        $sortBy = $request->get('sort_by', 'created_at');
        switch ($sortBy) {
            case 'price_asc':  $query->orderBy('price', 'asc'); break;
            case 'price_desc': $query->orderBy('price', 'desc'); break;
            case 'rating':     $query->orderBy('rating', 'desc'); break;
            case 'popular':    $query->orderBy('total_bookings', 'desc'); break;
            default:           $query->orderBy($sortBy, $request->get('sort_order', 'desc'));
        }

        return response()->json($query->paginate($request->get('per_page', 12)));
    }

    public function show($id)
    {
        $tour = Tour::with([
            'agency.user', 'category', 'images',
            'reviews' => fn($q) => $q->approved()->latest()->limit(10),
            'reviews.user',
        ])->findOrFail($id);

        return response()->json($tour);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (!$user->isAgency() || !$user->agency) {
            return response()->json(['message' => 'Solo las agencias pueden crear tours'], 403);
        }

        $validated = $request->validate([
            'category_id'          => 'required|exists:categories,id',
            'title'                => 'required|string|max:255',
            'description'          => 'required|string',
            'itinerary'            => 'nullable|string',
            'includes'             => 'nullable|string',
            'excludes'             => 'nullable|string',
            'requirements'         => 'nullable|string',
            'cancellation_policy'  => 'nullable|string',
            'cancellation_hours'   => 'nullable|integer|min:0',
            'price'                => 'required|numeric|min:0',
            'discount_price'       => 'nullable|numeric|min:0|lt:price',
            'duration_days'        => 'required|integer|min:0',
            'duration_hours'       => 'nullable|integer|min:0|max:23',
            'max_people'           => 'required|integer|min:1',
            'min_people'           => 'nullable|integer|min:1',
            'difficulty_level'     => 'nullable|in:easy,moderate,hard',
            'location_city'        => 'required|string|max:100',
            'location_region'      => 'required|string|max:100',
            'location_country'     => 'nullable|string|max:100',
            'latitude'             => 'nullable|numeric',
            'longitude'            => 'nullable|numeric',
            'is_published'         => 'nullable|boolean',
            'available_from'       => 'nullable|date',
            'available_to'         => 'nullable|date|after:available_from',
            'available_days'       => 'nullable|array',
            // Imagen destacada: archivo O URL (uno de los dos es obligatorio)
            'featured_image'       => 'nullable|image|max:5120',
            'featured_image_url'   => 'nullable|url|max:2048',
            // Imágenes adicionales
            'additional_images'    => 'nullable|array|max:4',
            'additional_images.*'  => 'image|max:5120',
            'additional_image_urls'   => 'nullable|array|max:4',
            'additional_image_urls.*' => 'nullable|url|max:2048',
        ]);

        // --- Imagen destacada ---
        $featuredImagePath = null;

        if ($request->hasFile('featured_image')) {
            $featuredImagePath = $request->file('featured_image')->store('tours', 'public');
        } elseif ($request->filled('featured_image_url')) {
            $featuredImagePath = $request->input('featured_image_url');
        } else {
            return response()->json([
                'message' => 'Debes proporcionar una imagen destacada (archivo o URL).',
                'errors'  => ['featured_image' => ['La imagen destacada es requerida.']],
            ], 422);
        }

        $validated['featured_image'] = $featuredImagePath;
        $validated['agency_id']      = $user->agency->id;
        $validated['slug']           = Str::slug($validated['title']);

        // Eliminar campos que no pertenecen a la tabla tours
        unset(
            $validated['featured_image_url'],
            $validated['additional_images'],
            $validated['additional_image_urls']
        );

        $tour = Tour::create($validated);

        // --- Imágenes adicionales ---
        $this->saveAdditionalImages($request, $tour);

        return response()->json([
            'message' => 'Tour creado exitosamente',
            'tour'    => $tour->load('category', 'images'),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $tour = Tour::findOrFail($id);
        $user = $request->user();

        if ($tour->agency_id !== $user->agency->id && !$user->isAdmin()) {
            return response()->json(['message' => 'No tienes permiso para editar este tour'], 403);
        }

        $validated = $request->validate([
            'category_id'         => 'sometimes|exists:categories,id',
            'title'               => 'sometimes|string|max:255',
            'description'         => 'sometimes|string',
            'price'               => 'sometimes|numeric|min:0',
            'discount_price'      => 'nullable|numeric|min:0',
            'max_people'          => 'sometimes|integer|min:1',
            'is_active'           => 'sometimes|boolean',
            'featured_image'      => 'nullable|image|max:5120',
            'featured_image_url'  => 'nullable|url|max:2048',
            'additional_images'   => 'nullable|array|max:4',
            'additional_images.*' => 'image|max:5120',
            'additional_image_urls'   => 'nullable|array|max:4',
            'additional_image_urls.*' => 'nullable|url|max:2048',
        ]);

        // Actualizar imagen destacada solo si se envió algo nuevo
        if ($request->hasFile('featured_image')) {
            if ($tour->featured_image && !filter_var($tour->featured_image, FILTER_VALIDATE_URL)) {
                Storage::disk('public')->delete($tour->featured_image);
            }
            $validated['featured_image'] = $request->file('featured_image')->store('tours', 'public');
        } elseif ($request->filled('featured_image_url')) {
            $validated['featured_image'] = $request->input('featured_image_url');
        }

        unset(
            $validated['featured_image_url'],
            $validated['additional_images'],
            $validated['additional_image_urls']
        );

        $tour->update($validated);

        // Imágenes adicionales nuevas
        $this->saveAdditionalImages($request, $tour);

        return response()->json([
            'message' => 'Tour actualizado exitosamente',
            'tour'    => $tour->load('category', 'images'),
        ]);
    }

    /**
     * Guarda imágenes adicionales (archivos o URLs) en tour_images.
     */
    private function saveAdditionalImages(Request $request, Tour $tour): void
    {
        $order = $tour->images()->max('order') ?? 0;

        // Archivos subidos
        if ($request->hasFile('additional_images')) {
            foreach ($request->file('additional_images') as $file) {
                $path = $file->store('tours', 'public');
                TourImage::create([
                    'tour_id'    => $tour->id,
                    'image_url'  => Storage::url($path),
                    'order'      => ++$order,
                    'is_primary' => false,
                ]);
            }
        }

        // URLs
        $urls = $request->input('additional_image_urls', []);
        foreach (array_filter((array) $urls) as $url) {
            TourImage::create([
                'tour_id'    => $tour->id,
                'image_url'  => $url,
                'order'      => ++$order,
                'is_primary' => false,
            ]);
        }
    }

    public function destroy($id)
    {
        $tour = Tour::findOrFail($id);
        $user = request()->user();

        if ($tour->agency_id !== $user->agency->id && !$user->isAdmin()) {
            return response()->json(['message' => 'No tienes permiso para eliminar este tour'], 403);
        }

        $tour->delete();

        return response()->json(['message' => 'Tour eliminado exitosamente']);
    }

    public function featured()
    {
        $tours = Tour::with(['agency', 'category', 'images'])
            ->active()->featured()->limit(8)->get();

        return response()->json($tours);
    }

    public function related($id)
    {
        $tour = Tour::findOrFail($id);

        $related = Tour::with(['agency', 'category', 'images'])
            ->active()
            ->where('id', '!=', $id)
            ->where(function ($q) use ($tour) {
                $q->where('category_id', $tour->category_id)
                  ->orWhere('location_city', $tour->location_city);
            })
            ->limit(4)->get();

        return response()->json($related);
    }
}