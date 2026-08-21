(function () {
    'use strict';

    var input = document.getElementById('inspection-photos');
    var preview = document.getElementById('inspection-preview');

    if (!input || !preview) {
        return;
    }

    input.addEventListener('change', function () {
        preview.innerHTML = '';
        var files = Array.prototype.slice.call(input.files || []);

        files.forEach(function (file) {
            if (!file.type || file.type.indexOf('image/') !== 0) {
                return;
            }

            var item = document.createElement('div');
            item.className = 'inspection-preview-item';

            var image = document.createElement('img');
            image.alt = '';

            var label = document.createElement('span');
            label.textContent = file.name;

            item.appendChild(image);
            item.appendChild(label);
            preview.appendChild(item);

            var reader = new FileReader();
            reader.onload = function (event) {
                image.src = event.target.result;
            };
            reader.readAsDataURL(file);
        });
    });
}());
