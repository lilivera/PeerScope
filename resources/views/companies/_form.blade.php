<section class="surface p-4">
    <form method="post" action="{{ $action }}">
        @csrf
        @if($method)
            @method($method)
        @endif

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="name">会社名</label>
                <input class="form-control" id="name" name="name" value="{{ old('name', $company->name) }}" required>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="official_url">公式URL</label>
                <input class="form-control" id="official_url" name="official_url" type="url" value="{{ old('official_url', $company->official_url) }}">
            </div>
            <div class="col-12">
                <label class="form-label" for="memo">メモ</label>
                <textarea class="form-control" id="memo" name="memo" rows="5">{{ old('memo', $company->memo) }}</textarea>
            </div>
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" id="is_active" name="is_active" type="checkbox" value="1" @checked(old('is_active', $company->is_active))>
                    <label class="form-check-label" for="is_active">有効</label>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 mt-4">
            <button class="btn btn-primary" type="submit">
                <i class="bi bi-check-lg me-1" aria-hidden="true"></i>{{ $button }}
            </button>
            <a class="btn btn-outline-secondary" href="{{ route('companies.index') }}">戻る</a>
        </div>
    </form>
</section>
