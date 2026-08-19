import * as THREE from "three";
import { OrbitControls } from "three/addons/controls/OrbitControls.js";
import { decodeHeights, smoothHeights } from "@/lib/maps/heights";
import type { MapPreview, PreviewAppearance } from "@/lib/maps/preview";

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

/** The sky behind an ordinary map, and behind one whose water is void. A void
 *  map is played in space, and the engine shows the skybox through the sea
 *  plane, so the honest backdrop for one is nearly black. */
const DEFAULT_SKY = 0x0a1018;
const VOID_SKY = 0x02030a;

/** How fast the view drifts when nobody has taken hold of it. Slow enough to
 *  read the relief from, and off entirely under reduced motion. */
const DRIFT_SPEED = 0.5;

/** Which way is up in the scene, which is the axis the relief runs along. */
const WORLD_UP = new THREE.Vector3(0, 1, 0);

export interface Terrain {
  /** Free the GL context, the geometry and the textures. Nothing here survives
   *  the component that made it. */
  dispose: () => void;
}

/**
 * A colour the archive declared, or the preview's own.
 *
 * The triple is read as sRGB and not as three's linear working space, which is
 * what the constructor would assume from three numbers. `mapinfo.lua` writes the
 * colour the engine displays, so a sky declared as 0.03, 0.06, 0.12 is a nearly
 * black sky. Handing those to the linear path renders it as a mid slate, which
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
  });

  return { geometry, material, mesh: new THREE.Mesh(geometry, material) };
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

  const renderer = new THREE.WebGLRenderer({ antialias: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
  renderer.domElement.style.display = "block";
  renderer.domElement.style.width = "100%";
  renderer.domElement.style.height = "100%";
  host.appendChild(renderer.domElement);

  const texture = new THREE.Texture(textureImage);
  texture.colorSpace = THREE.SRGBColorSpace;
  texture.needsUpdate = true;

  const scene = new THREE.Scene();
  scene.background = colour(appearance.sky, preview.voidWater ? VOID_SKY : DEFAULT_SKY);
  if (appearance.fog) {
    scene.fog = new THREE.Fog(colour(appearance.fog, DEFAULT_SKY), BASE * 0.8, BASE * 3);
  }

  const ground = terrainMesh(grid, preview, scale, texture);
  scene.add(ground.mesh);

  // A void water map has no sea at all: the engine shows the sky through it,
  // and drawing a translucent sheet over an asteroid would be the hub inventing
  // an ocean the archive says is not there.
  const sea = preview.voidWater ? null : waterMesh(preview, scale, appearance);
  if (sea) scene.add(sea.mesh);

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
      texture.dispose();
      renderer.dispose();
      renderer.domElement.remove();
    },
  };
}
