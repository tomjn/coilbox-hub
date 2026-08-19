import * as THREE from "three";
import { OrbitControls } from "three/addons/controls/OrbitControls.js";
import { decodeHeights, smoothHeights } from "@/lib/maps/heights";
import type { MapPreview, PreviewAppearance, PreviewPoint } from "@/lib/maps/preview";

/**
 * The map drawn as terrain, in three.js (#194).
 *
 * The only module in the hub that imports three, and it is imported from
 * exactly one place: an `await import()` inside `MapPreview.tsx`, behind the
 * button that opens the view. Everything three brings with it therefore lands in
 * a chunk nothing fetches until somebody asks for it, so a map page costs
 * nothing extra to read and `/maps` never pulls it at all. Keep it that way. A
 * static import of this file from anywhere is a bundle regression that nothing
 * else will catch.
 *
 * ## The relief is displaced on the processor, not in the vertex shader
 *
 * three's `displacementMap` moves each vertex along the plane's own normal and
 * leaves the normals as they were, so the terrain lights as if it were still
 * flat: relief shows in silhouette and nowhere else. Reading the picture once,
 * writing the heights into the geometry and calling `computeVertexNormals` costs
 * a few milliseconds on a mesh this size and gives ground that catches the light
 * the way ground does.
 *
 * It is also the only way the eight bit steps come out. `lib/maps/heights.ts`
 * argues that at length: quantisation is what terraces, and interpolating
 * between two equal samples cannot undo it, so the field is low passed before it
 * ever becomes geometry.
 *
 * ## It carries the same three layers the flat figure does
 *
 * Start positions, metal spots and geothermal vents, from the same stored points
 * `components/MapFigure.tsx` draws over the minimap. The 3D view replaces that
 * figure once it has drawn, so anything only the figure showed would be a fact
 * the reader loses by having a better browser.
 *
 * Two of the three are dots and the third is not. A vent is what a player scans
 * the map for, and `ventLayer` says why that makes it a plume.
 *
 * The heights come off the relief rather than off the points. `map_point.y` is
 * null on almost every start position, and this module has the ground in front
 * of it.
 *
 * ## Both pictures are read pixel by pixel, so both need CORS
 *
 * The durable tier is GitHub Pages and the staging tier is Vercel Blob. Both
 * answer `access-control-allow-origin: *`, so `crossOrigin = "anonymous"` is
 * enough and the canvas stays untainted. Without it `getImageData` throws and
 * WebGL refuses the texture, which is why a failure here is reported to the
 * visitor rather than swallowed.
 *
 * ## What it follows
 *
 * coilbox's `galaxy3d` and its own `MapPreview3D`, for the parts that are the
 * same problem: `OrbitControls` from three's addons, a `ResizeObserver` on the
 * host rather than a window listener, `setSize(w, h, false)` with the canvas
 * sized in CSS, the device pixel ratio clamped at 2, a disposables list, and
 * drawing on demand rather than in a loop.
 */

/** The scene extent the longer side of the map is normalised to. Scene
 *  coordinates then stay the same size whatever the map is, and the vertical
 *  scale is the same factor, so relief stays physically true. */
const BASE = 100;

/** Vertices a side, at most. The overlay is capped at 512 pixels on its long
 *  edge, and a mesh finer than the picture draws detail that is not there. */
const MAX_SEGMENTS = 256;

/** Where the light comes from when the archive does not say. Off to one side and
 *  well up, which is the angle that shows a ridge without flattening a valley. */
const DEFAULT_SUN: [number, number, number] = [-0.6, 0.9, 0.4];

/** A sea for a map that declares no colour of its own. */
const DEFAULT_WATER = 0x2f6f9f;
const DEFAULT_WATER_ALPHA = 0.55;

/** The two dotted layers, in `components/MapFigure.tsx`'s own colours, so a
 *  metal spot is the same amber in both pictures of the same map. */
const START_COLOUR = 0xf5f5f5;
const METAL_COLOUR = 0xfcd34d;

/** A dot's radius, and how far it is lifted clear of the ground so it is not
 *  half buried in the slope it sits on. Shares of {@link BASE}, so a marker is
 *  the same size on a small map and a large one. */
const MARKER_RADIUS = BASE * 0.011;
const MARKER_LIFT = BASE * 0.006;

/** The plume over a geothermal vent: its colour, how far it rises and how wide
 *  it is. Tall enough to find from across the map, which is the whole reason it
 *  is a plume rather than a dot. */
const GEO_COLOUR = 0xffd93b;
const VENT_HEIGHT = BASE * 0.18;
const VENT_RADIUS = BASE * 0.011;

/** Sea level, which is elmo height zero and scene height zero. What a void map
 *  keeps and everything below it is what a void map does not have. */
const SEA_LEVEL = new THREE.Plane(new THREE.Vector3(0, 1, 0), 0);

/** How fast the view drifts when nobody has taken hold of it. Slow enough to
 *  read the relief from, and off entirely under reduced motion. */
const DRIFT_SPEED = 0.5;

/** Which way is up in the scene, which is the axis the relief runs along. */
const WORLD_UP = new THREE.Vector3(0, 1, 0);

export interface Terrain {
  /** Show or hide the two layers the flat figure also puts behind a toggle.
   *  Start positions are not among them: they are the fact the picture is there
   *  to carry, in this view as in that one. */
  setLayers: (layers: { metal: boolean; geo: boolean }) => void;
  /** Free the GL context, the geometry and the textures. Nothing here survives
   *  the component that made it. */
  dispose: () => void;
}

/** Everything one layer of markers owns, so the caller can hide it and then
 *  throw it away without knowing what it is made of. */
interface Layer {
  mesh: THREE.Object3D;
  dispose: () => void;
}

/**
 * A colour the archive declared, or the preview's own.
 *
 * The triple is read as sRGB and not as three's linear working space, which is
 * what the constructor would assume from three numbers. `mapinfo.lua` writes the
 * colour the engine displays, so a sea declared as 0.03, 0.06, 0.12 is a nearly
 * black sea. Handing those to the linear path renders it as a mid slate, which
 * is the same three numbers meaning something four times brighter. The hex
 * fallbacks need no such care: `setHex` already reads sRGB.
 */
function colour(rgb: [number, number, number] | null, fallback: number): THREE.Color {
  return rgb
    ? new THREE.Color().setRGB(rgb[0], rgb[1], rgb[2], THREE.SRGBColorSpace)
    : new THREE.Color(fallback);
}

/**
 * One image, decodable pixel by pixel.
 *
 * `crossOrigin` before `src`, which is the order the property has to be set in:
 * assigning it afterwards does not restart a load that has already begun.
 */
function loadImage(url: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const image = new Image();
    image.crossOrigin = "anonymous";
    image.onload = () => resolve(image);
    image.onerror = () => reject(new Error(`could not load ${url}`));
    image.src = url;
  });
}

/** The map's heights, in elmos, with the eight bit steps taken out. */
function heightGrid(image: HTMLImageElement, preview: MapPreview) {
  const width = image.naturalWidth;
  const height = image.naturalHeight;

  const canvas = document.createElement("canvas");
  canvas.width = width;
  canvas.height = height;
  const context = canvas.getContext("2d", { willReadFrequently: true });
  if (!context) throw new Error("this browser has no 2D canvas to read the overlay with");

  context.drawImage(image, 0, 0);
  const { data } = context.getImageData(0, 0, width, height);

  return {
    width,
    height,
    elmos: smoothHeights(decodeHeights(data, preview.range), width, height),
  };
}

/** The height at a fractional position in the grid, so a mesh coarser or finer
 *  than the picture still follows it. Clamped at the edges, which is where a
 *  rounding error would otherwise read off the end of the row and wrap the far
 *  side of the map onto the near one. */
function sampleGrid(
  grid: { width: number; height: number; elmos: Float32Array },
  u: number,
  v: number,
): number {
  const x = u * (grid.width - 1);
  const y = v * (grid.height - 1);
  const x0 = Math.min(grid.width - 1, Math.max(0, Math.floor(x)));
  const y0 = Math.min(grid.height - 1, Math.max(0, Math.floor(y)));
  const x1 = Math.min(grid.width - 1, x0 + 1);
  const y1 = Math.min(grid.height - 1, y0 + 1);
  const fx = x - x0;
  const fy = y - y0;

  const top = grid.elmos[y0 * grid.width + x0] * (1 - fx) + grid.elmos[y0 * grid.width + x1] * fx;
  const bottom =
    grid.elmos[y1 * grid.width + x0] * (1 - fx) + grid.elmos[y1 * grid.width + x1] * fx;

  return top * (1 - fy) + bottom * fy;
}

/**
 * The ground, at the map's own proportions and with its own relief.
 *
 * The picture's top row is the far edge, which is the same reading
 * `components/MapFigure.tsx` takes when it stretches the whole minimap over the
 * whole frame. The two have to agree, because the same picture is the flat
 * figure and this surface.
 */
function terrainMesh(
  grid: { width: number; height: number; elmos: Float32Array },
  preview: MapPreview,
  scale: number,
  texture: THREE.Texture,
) {
  const across = Math.min(MAX_SEGMENTS, grid.width - 1);
  const down = Math.min(MAX_SEGMENTS, grid.height - 1);

  const geometry = new THREE.PlaneGeometry(
    preview.widthElmos * scale,
    preview.heightElmos * scale,
    across,
    down,
  );
  // Lie flat in XZ with height along +Y. Done before the heights are written, so
  // the axis they are written to is the one they end up on.
  geometry.rotateX(-Math.PI / 2);

  const position = geometry.attributes.position;
  for (let row = 0; row <= down; row++) {
    for (let column = 0; column <= across; column++) {
      const elmos = sampleGrid(grid, column / across, row / down);
      position.setY(row * (across + 1) + column, elmos * scale);
    }
  }
  position.needsUpdate = true;
  // The whole reason the displacement happens here. Without this every face
  // keeps the flat plane's normal and the terrain lights as if it had none.
  geometry.computeVertexNormals();

  const material = new THREE.MeshStandardMaterial({
    map: texture,
    roughness: 1,
    metalness: 0,
    // A void map is played over nothing, and the engine draws neither the sea
    // nor the ground beneath where the sea would have been. Clipping at sea
    // level is that, exactly: the terrain is one surface rather than a solid, so
    // cutting it at zero removes the sea bed and leaves the coastline where the
    // archive put it. Nothing else in the scene is clipped, so a marker on the
    // shore keeps its dot.
    clippingPlanes: preview.voidWater ? [SEA_LEVEL] : null,
  });

  return { geometry, material, mesh: new THREE.Mesh(geometry, material) };
}

/**
 * Where a point sits in the scene, with its height read off the relief.
 *
 * The two divisions are `markerPosition`'s, moved to the middle-origin the mesh
 * is built around: the plane runs from minus half the map to plus half of it on
 * both axes, and the picture's top row is the far edge on both.
 *
 * The height is the ground's rather than the point's. `map_point.y` is null on
 * almost every start position because the engine resolves a spawn height from
 * the terrain, and the terrain is the thing this function has just decoded.
 */
function scenePosition(
  point: PreviewPoint,
  grid: { width: number; height: number; elmos: Float32Array },
  preview: MapPreview,
  scale: number,
): THREE.Vector3 {
  const u = Math.min(1, Math.max(0, point.x / preview.widthElmos));
  const v = Math.min(1, Math.max(0, point.z / preview.heightElmos));

  return new THREE.Vector3(
    (point.x - preview.widthElmos / 2) * scale,
    sampleGrid(grid, u, v) * scale,
    (point.z - preview.heightElmos / 2) * scale,
  );
}

/**
 * One dotted layer, as instances of a single sphere.
 *
 * Unlit, so a metal spot on a slope facing away from the sun is as findable as
 * one facing it. The marker is a fact about the map rather than a thing standing
 * on it, and the flat figure draws it in flat colour for the same reason.
 *
 * One draw call however many spots there are. A busy map carries a few hundred
 * between the two layers, which is a mesh each if they are built the obvious
 * way.
 */
function markerLayer(
  points: PreviewPoint[],
  colour: number,
  grid: { width: number; height: number; elmos: Float32Array },
  preview: MapPreview,
  scale: number,
): Layer | null {
  if (points.length === 0) return null;

  const geometry = new THREE.SphereGeometry(MARKER_RADIUS, 12, 8);
  const material = new THREE.MeshBasicMaterial({ color: colour });
  const mesh = new THREE.InstancedMesh(geometry, material, points.length);
  const matrix = new THREE.Matrix4();

  points.forEach((point, index) => {
    const at = scenePosition(point, grid, preview, scale);
    mesh.setMatrixAt(index, matrix.makeTranslation(at.x, at.y + MARKER_LIFT, at.z));
  });
  mesh.instanceMatrix.needsUpdate = true;

  return {
    mesh,
    dispose: () => {
      geometry.dispose();
      material.dispose();
      mesh.dispose();
    },
  };
}

/**
 * The geothermal vents, as plumes rather than as dots.
 *
 * A vent is a thing a player looks for from across the map, and a dot the size
 * of a metal spot is not findable on ground seen at an angle. Every game that
 * shows one draws a column of light standing on it, so this does: a yellow tube
 * rising out of the vent, solid where it meets the ground and gone by the top.
 *
 * The fade is written into the geometry as vertex alpha rather than into a
 * texture. There is then no picture to load, no second request to wait on, and
 * no argument about which way up a texture's v axis runs. three reads a four
 * component colour attribute as RGBA, which `transparent` blends by.
 *
 * Additive, and it does not write depth. Two vents close together read as
 * brighter rather than as one cutting a hole through the other, and neither
 * paints over the ground behind it. It still tests depth, so a vent over the far
 * side of a ridge is hidden by the ridge, which is what tells a player it is
 * over there rather than here.
 */
function ventLayer(
  points: PreviewPoint[],
  grid: { width: number; height: number; elmos: Float32Array },
  preview: MapPreview,
  scale: number,
): Layer | null {
  if (points.length === 0) return null;

  const geometry = new THREE.CylinderGeometry(VENT_RADIUS, VENT_RADIUS, VENT_HEIGHT, 10, 12, true);
  // Standing on the ground rather than half sunk into it: a cylinder is built
  // around its own middle.
  geometry.translate(0, VENT_HEIGHT / 2, 0);

  const position = geometry.attributes.position;
  const colours = new Float32Array(position.count * 4);
  // Read as sRGB by the constructor and handed on in three's working space,
  // which is what a vertex colour attribute is taken to be in.
  const tint = new THREE.Color(GEO_COLOUR);
  for (let vertex = 0; vertex < position.count; vertex++) {
    const up = position.getY(vertex) / VENT_HEIGHT;
    colours[vertex * 4] = tint.r;
    colours[vertex * 4 + 1] = tint.g;
    colours[vertex * 4 + 2] = tint.b;
    // Squared, so the plume is bright at the vent and most of its length is a
    // hint rather than a bar drawn across the view.
    colours[vertex * 4 + 3] = (1 - up) ** 2;
  }
  geometry.setAttribute("color", new THREE.BufferAttribute(colours, 4));

  const material = new THREE.MeshBasicMaterial({
    vertexColors: true,
    transparent: true,
    depthWrite: false,
    side: THREE.DoubleSide,
    blending: THREE.AdditiveBlending,
  });

  const mesh = new THREE.InstancedMesh(geometry, material, points.length);
  const matrix = new THREE.Matrix4();
  points.forEach((point, index) => {
    const at = scenePosition(point, grid, preview, scale);
    mesh.setMatrixAt(index, matrix.makeTranslation(at.x, at.y, at.z));
  });
  mesh.instanceMatrix.needsUpdate = true;

  return {
    mesh,
    dispose: () => {
      geometry.dispose();
      material.dispose();
      mesh.dispose();
    },
  };
}

/** The sea, at elmo height zero, which is scene height zero. */
function waterMesh(preview: MapPreview, scale: number, appearance: PreviewAppearance) {
  const geometry = new THREE.PlaneGeometry(
    preview.widthElmos * scale,
    preview.heightElmos * scale,
  );
  geometry.rotateX(-Math.PI / 2);

  const material = new THREE.MeshStandardMaterial({
    color: colour(appearance.water, DEFAULT_WATER),
    transparent: true,
    opacity: appearance.waterAlpha ?? DEFAULT_WATER_ALPHA,
    roughness: 0.15,
    metalness: 0,
  });

  return { geometry, material, mesh: new THREE.Mesh(geometry, material) };
}

/**
 * Draw `preview` inside `host`, and hand back the way to take it down again.
 *
 * Throws when the browser has no WebGL, when either picture will not load, or
 * when the overlay cannot be read pixel by pixel. The caller shows the visitor
 * what happened: this is a view somebody asked for by pressing a button, and
 * silently leaving them with an empty box is worse than saying it did not work.
 */
export async function drawTerrain(
  host: HTMLElement,
  preview: MapPreview,
  reduceMotion: boolean,
): Promise<Terrain> {
  const [heightImage, textureImage] = await Promise.all([
    loadImage(preview.heightUrl),
    loadImage(preview.textureUrl),
  ]);

  const grid = heightGrid(heightImage, preview);
  const scale = BASE / Math.max(preview.widthElmos, preview.heightElmos);
  const { appearance } = preview;

  // Transparent, so the map sits on the page rather than in a window cut into
  // it. A skybox is what a game needs and this is a figure in an article: the
  // scene has no horizon, no distance and nothing else in it, so a backdrop
  // could only be a rectangle of colour the terrain does not fill.
  const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
  renderer.setClearAlpha(0);
  // Off unless a material asks for it, which on a void map the ground does.
  renderer.localClippingEnabled = true;
  renderer.domElement.style.display = "block";
  renderer.domElement.style.width = "100%";
  renderer.domElement.style.height = "100%";
  host.appendChild(renderer.domElement);

  const texture = new THREE.Texture(textureImage);
  texture.colorSpace = THREE.SRGBColorSpace;
  texture.needsUpdate = true;

  // No background and no fog. Both would have to be drawn as opaque colour over
  // the page behind the canvas, which is the sky this view is meant not to have.
  const scene = new THREE.Scene();

  const ground = terrainMesh(grid, preview, scale, texture);
  scene.add(ground.mesh);

  // A void water map has no sea at all: the engine shows the void through it,
  // and drawing a translucent sheet over an asteroid would be the hub inventing
  // an ocean the archive says is not there. The ground under it is gone too, in
  // `terrainMesh`.
  const sea = preview.voidWater ? null : waterMesh(preview, scale, appearance);
  if (sea) scene.add(sea.mesh);

  // The same three layers the flat figure draws, from the same stored points.
  const layers = {
    start: markerLayer(preview.points.start, START_COLOUR, grid, preview, scale),
    metal: markerLayer(preview.points.metal, METAL_COLOUR, grid, preview, scale),
    geo: ventLayer(preview.points.geo, grid, preview, scale),
  };
  for (const layer of [layers.start, layers.metal, layers.geo]) {
    if (layer) scene.add(layer.mesh);
  }
  // Shut to begin with, the same as the checkboxes over the flat figure. Start
  // positions are not a layer somebody turns on.
  if (layers.metal) layers.metal.mesh.visible = false;
  if (layers.geo) layers.geo.mesh.visible = false;

  const [sx, sy, sz] = appearance.sunDirection ?? DEFAULT_SUN;
  const sun = new THREE.DirectionalLight(colour(appearance.sunColour, 0xffffff), 1.7);
  sun.position.set(sx, sy, sz).normalize().multiplyScalar(BASE);
  scene.add(sun, new THREE.AmbientLight(0xffffff, 1.0));

  const camera = new THREE.PerspectiveCamera(45, 1, 0.1, 1000);
  // The angle the map is first seen from. How far away that is comes later, from
  // the shape of the map and the shape of the frame it has to fit in.
  const viewpoint = new THREE.Vector3(0, 0.75, 0.95).normalize();

  const controls = new OrbitControls(camera, renderer.domElement);
  // Damping needs a frame every frame to settle. Without it the scene draws only
  // when something changed, which is what lets a still preview cost nothing.
  controls.enableDamping = false;
  // Just short of the horizon, so the camera never drops level with the ground
  // and looks along an infinitely thin plane.
  controls.maxPolarAngle = Math.PI * 0.49;
  controls.zoomToCursor = true;
  // One finger turns the map and two zoom it. The preview is inside a page that
  // scrolls, and OrbitControls takes the touch either way, so the gesture worth
  // giving the single finger is the one somebody opened the view for.
  controls.touches = { ONE: THREE.TOUCH.ROTATE, TWO: THREE.TOUCH.DOLLY_ROTATE };

  // The camera never goes below the highest ground, which is what stops a zoom
  // ending up inside a mountain. OrbitControls clamps distance from a target
  // that moves under `zoomToCursor` and cannot see the surface, so the floor has
  // to be applied to the position itself.
  const peak = Math.max(preview.range.max * scale, 0) + BASE * 0.03;
  const render = () => {
    if (camera.position.y < peak) camera.position.y = peak;
    renderer.render(scene, camera);
  };
  controls.addEventListener("change", render);

  /**
   * The whole map in the frame, once, from any angle.
   *
   * Worked out rather than picked, because a fixed distance frames a square map
   * and cuts the ends off a 12 x 20. Every corner of the terrain is pushed
   * through both fields of view and the furthest one decides: a wide short frame
   * runs out of height first and a tall narrow one runs out of width, and a
   * mountain at the near edge needs more room than the same mountain at the far
   * one.
   *
   * The eight corners rather than a bounding sphere. A sphere around a plate
   * shaped thing is mostly air, and fitting the sphere leaves the map a third of
   * the size it could be.
   *
   * Every angle rather than the one it opens at, because the view drifts. A
   * distance that frames a wide map end on lets the same map's diagonal swing
   * out of the bottom of the frame a few seconds later, which is worse than
   * starting a little further back.
   *
   * Once only. After the first fit the distance is the visitor's, and a resize
   * that re-framed would undo their zoom every time a phone was turned.
   */
  const bounds = new THREE.Box3().setFromObject(ground.mesh);
  // A void map's sea bed is clipped away, and the geometry it was cut out of is
  // still the geometry the box was measured from. Fitting to that would frame a
  // few hundred elmos of nothing under the map and draw the map small to do it.
  if (preview.voidWater) bounds.min.y = Math.max(bounds.min.y, 0);
  const corners: THREE.Vector3[] = [];
  for (const x of [bounds.min.x, bounds.max.x]) {
    for (const y of [bounds.min.y, bounds.max.y]) {
      for (const z of [bounds.min.z, bounds.max.z]) corners.push(new THREE.Vector3(x, y, z));
    }
  }

  /** How far back the camera has to sit to hold the whole map, looking from
   *  `direction`. */
  const distanceFrom = (direction: THREE.Vector3) => {
    const vertical = THREE.MathUtils.degToRad(camera.fov);
    const horizontal = 2 * Math.atan(Math.tan(vertical / 2) * camera.aspect);
    const tanVertical = Math.tan(vertical / 2);
    const tanHorizontal = Math.tan(horizontal / 2);

    // The camera's own axes at the angle it would look from, so a corner can be
    // measured across the frame and up it rather than in world terms.
    const across = new THREE.Vector3().crossVectors(WORLD_UP, direction).normalize();
    const up = new THREE.Vector3().crossVectors(direction, across).normalize();

    let distance = 0;
    const offset = new THREE.Vector3();
    for (const corner of corners) {
      offset.copy(corner).sub(controls.target);
      // How far the corner already is towards the camera. A near corner has to
      // be backed away from further than a far one.
      const towards = offset.dot(direction);
      distance = Math.max(
        distance,
        towards + Math.abs(offset.dot(across)) / tanHorizontal,
        towards + Math.abs(offset.dot(up)) / tanVertical,
      );
    }

    return distance;
  };

  /** How many angles the fit is checked at as the view turns right round. Every
   *  four degrees, which is finer than the difference a corner makes. */
  const AZIMUTHS = 90;

  let framed = false;
  const fitToFrame = () => {
    const turned = new THREE.Vector3();
    let distance = 0;
    for (let step = 0; step < AZIMUTHS; step++) {
      turned.copy(viewpoint).applyAxisAngle(WORLD_UP, (step / AZIMUTHS) * Math.PI * 2);
      distance = Math.max(distance, distanceFrom(turned));
    }
    // A little air, so the map is not wedged against the sides of its frame.
    distance *= 1.08;

    camera.position.copy(controls.target).addScaledVector(viewpoint, distance);
    controls.maxDistance = distance * 2.5;
    controls.minDistance = distance * 0.12;
    // Moving the camera is only half of putting it somewhere. OrbitControls aims
    // it at the target, and it does that in `update`, so a camera moved without
    // one keeps the direction it was last pointed and renders empty sky.
    controls.update();
  };

  const resize = () => {
    const { clientWidth, clientHeight } = host;
    if (clientWidth === 0 || clientHeight === 0) return;
    // The canvas is sized in CSS above, so three must not write its own
    // attributes over that: an unsized canvas falls back to the drawing buffer,
    // which is larger than its host, which grows the host, which grows the
    // canvas.
    renderer.setSize(clientWidth, clientHeight, false);
    camera.aspect = clientWidth / clientHeight;
    camera.updateProjectionMatrix();
    if (!framed) {
      fitToFrame();
      framed = true;
    }
    render();
  };

  const observer = new ResizeObserver(resize);
  observer.observe(host);
  resize();

  // A slow drift, so the view reads as terrain rather than as a photograph, and
  // it stops for good the moment the visitor takes hold. Nothing else animates,
  // so under reduced motion there is no loop at all and the scene draws only
  // when it changes.
  let frame: number | undefined;
  controls.autoRotate = !reduceMotion;
  controls.autoRotateSpeed = DRIFT_SPEED;
  const stopDrift = () => {
    controls.autoRotate = false;
  };
  controls.addEventListener("start", stopDrift);

  if (!reduceMotion) {
    const drift = () => {
      frame = requestAnimationFrame(drift);
      if (controls.autoRotate) controls.update();
    };
    frame = requestAnimationFrame(drift);
  }

  return {
    setLayers: ({ metal, geo }) => {
      if (layers.metal) layers.metal.mesh.visible = metal;
      if (layers.geo) layers.geo.mesh.visible = geo;
      render();
    },
    dispose: () => {
      if (frame !== undefined) cancelAnimationFrame(frame);
      observer.disconnect();
      controls.removeEventListener("change", render);
      controls.removeEventListener("start", stopDrift);
      controls.dispose();
      ground.geometry.dispose();
      ground.material.dispose();
      sea?.geometry.dispose();
      sea?.material.dispose();
      layers.start?.dispose();
      layers.metal?.dispose();
      layers.geo?.dispose();
      texture.dispose();
      renderer.dispose();
      renderer.domElement.remove();
    },
  };
}
