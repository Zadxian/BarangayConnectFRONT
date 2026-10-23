<?php include APP_DIR . 'views/partials/header.php'; ?>
<section class="card" style="max-width:720px">
  <h1><?= e($title) ?></h1>
  <p class="muted"><?= e($intro) ?></p>
  <form method="post" action="<?= site_url($action) ?>" enctype="multipart/form-data">
    <?php csrf_field(); ?>
    <label for="incident_type_id">Incident type</label>
    <select id="incident_type_id" name="incident_type_id" required>
      <option value="">Choose one</option>
      <?php foreach ($types as $type): ?><option value="<?= e($type['value']) ?>"><?= e($type['label']) ?></option><?php endforeach; ?>
    </select>
    <label for="incident_date">Date of incident</label><input id="incident_date" name="incident_date" type="date" required max="<?= e(date('Y-m-d')) ?>">
    <label for="location">Location / address</label><input id="location" name="location" required maxlength="255">
    <?php if ($maps_api_key !== ''): ?>
      <p class="muted" style="margin-top:8px">Click the map to pin the incident location. The address will be filled in when Google Maps can identify it.</p>
      <div id="incident-map" style="height:320px;border:1px solid var(--line);border-radius:8px"></div>
    <?php endif; ?>
    <label for="latitude">Latitude (optional)</label><input id="latitude" name="latitude" type="number" step="any" min="-90" max="90">
    <label for="longitude">Longitude (optional)</label><input id="longitude" name="longitude" type="number" step="any" min="-180" max="180">
    <label for="description">Description</label><textarea id="description" name="description" maxlength="5000" required></textarea>
    <label for="evidence">Photo or document evidence (optional; PDF, JPEG, PNG; max 5 MB)</label>
    <input id="evidence" name="evidence" type="file" accept=".pdf,.jpg,.jpeg,.png">
    <button class="btn" type="submit"><?= e($button) ?></button>
  </form>
</section>
<?php if ($maps_api_key !== ''): ?>
<script>
  function initIncidentMap() {
    const initial = {lat: 12.8797, lng: 121.7740};
    const map = new google.maps.Map(document.getElementById('incident-map'), {center: initial, zoom: 6});
    const marker = new google.maps.Marker({map, draggable: true});
    const geocoder = new google.maps.Geocoder();
    const placePin = (position) => {
      marker.setPosition(position);
      map.panTo(position);
      document.getElementById('latitude').value = position.lat().toFixed(7);
      document.getElementById('longitude').value = position.lng().toFixed(7);
      geocoder.geocode({location: position}, (results, status) => {
        if (status === 'OK' && results && results[0]) {
          document.getElementById('location').value = results[0].formatted_address;
        }
      });
    };
    map.addListener('click', (event) => placePin(event.latLng));
    marker.addListener('dragend', (event) => placePin(event.latLng));
  }
</script>
<script async defer src="https://maps.googleapis.com/maps/api/js?key=<?= rawurlencode($maps_api_key) ?>&callback=initIncidentMap"></script>
<?php endif; ?>
<?php include APP_DIR . 'views/partials/footer.php'; ?>
