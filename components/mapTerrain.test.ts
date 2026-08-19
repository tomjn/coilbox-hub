import { expect, test } from "bun:test";
import * as THREE from "three";
import { groundPicker } from "@/components/mapTerrain";

/**
 * That a wheel turn over the scene only zooms when there is map under it.
 *
 * ## Why this is the part that gets tested
 *
 * The rest of `mapTerrain.ts` needs a GL context and two decoded pictures, and a
 * `bun test` run has neither. Raycasting needs neither either: it is arithmetic
 * against a geometry, so the decision that separates a zoom from a page scroll
 * can be put in front of a camera and asked.
 *
 * The frame is 600 x 400 and the camera looks down at the origin from 45
 * degrees, which is close enough to the scene's own opening angle that the
 * numbers below read the way the view does.
 */

/** Where the canvas is, as `getBoundingClientRect` would report it. */
const FRAME = { left: 0, top: 0, width: 600, height: 400 };

/** The middle of that frame, and a point half way up its top half. */
const CENTRE = { clientX: 300, clientY: 200 };

function camera(): THREE.PerspectiveCamera {
  const view = new THREE.PerspectiveCamera(45, FRAME.width / FRAME.height, 0.1, 1000);
  view.position.set(0, 141.4, 141.4);
  view.lookAt(0, 0, 0);
  view.updateMatrixWorld();
  return view;
}

/** A flat plate lying in XZ, `size` a side, centred on `at`. Stands in for the
 *  relief: what is being tested is whether a ray finds ground, not what shape
 *  the ground is. */
function plate(size: number, at: THREE.Vector3 = new THREE.Vector3()): THREE.Mesh {
  const geometry = new THREE.PlaneGeometry(size, size);
  geometry.rotateX(-Math.PI / 2);
  geometry.translate(at.x, at.y, at.z);
  return new THREE.Mesh(geometry);
}

test("a pointer over the ground zooms", () => {
  const over = groundPicker(camera(), plate(200), false);

  expect(over(CENTRE, FRAME)).toBe(true);
});

/** The canvas fills a frame three to two and the map is a plate seen at an
 *  angle, so most of what the pointer can be over is nothing at all. That is the
 *  whole reason this exists: the page has to keep scrolling there. */
test("a pointer over empty sky does not", () => {
  const over = groundPicker(camera(), plate(20), false);

  expect(over({ clientX: 20, clientY: 20 }, FRAME)).toBe(false);
});

/** A frame's top is the far side of the map, and the sign that says so is one
 *  character. Getting it wrong leaves a view that zooms in a mirror of itself,
 *  which is why the target here sits at the far edge rather than in the middle. */
test("the frame is read the right way up", () => {
  const far = plate(60, new THREE.Vector3(0, 0, -74));
  const over = groundPicker(camera(), far, false);

  expect(over({ clientX: 300, clientY: 100 }, FRAME)).toBe(true);
  expect(over({ clientX: 300, clientY: 300 }, FRAME)).toBe(false);
});

/**
 * A void map is played over nothing and its sea bed is clipped away, so the
 * ground below sea level is drawn nowhere and the reader is looking through it
 * at the page. Clipping is a rendering concern and a ray knows nothing about it,
 * so the surface a ray finds down there has to be discarded by hand.
 */
test("a void map's clipped sea bed is not ground", () => {
  const bed = plate(200, new THREE.Vector3(0, -10, 0));

  expect(groundPicker(camera(), bed, true)(CENTRE, FRAME)).toBe(false);
  expect(groundPicker(camera(), bed, false)(CENTRE, FRAME)).toBe(true);
});

/** The frame is in the markup from the start and measures nothing until it is
 *  shown, so a wheel turn can arrive before there is anything to divide by. */
test("a frame with no size is not ground", () => {
  const over = groundPicker(camera(), plate(200), false);

  expect(over(CENTRE, { left: 0, top: 0, width: 0, height: 0 })).toBe(false);
});
